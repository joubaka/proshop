<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\InvoiceScan;
use App\InvoiceScanDocument;
use App\Jobs\ProcessInvoiceScan;
use App\PurchaseLine;
use App\InvoiceScanning\PostScannedPurchase;
use App\InvoiceScanning\InvoiceAmounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class InvoiceScanController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeCreate();
        $businessId = (int) $request->session()->get('user.business_id');
        $scans = InvoiceScan::where('business_id', $businessId)->with(['supplier', 'location'])->latest()->paginate(20);
        $locations = BusinessLocation::forDropdown($businessId, false, false);
        return view('invoice_scans.index', compact('scans', 'locations'));
    }

    public function store(Request $request)
    {
        $this->authorizeCreate();
        $max = (int) config('invoice_scanning.max_file_kb', 12288);
        $request->validate([
            'location_id' => 'required|integer',
            'documents' => 'required|array|min:1|max:'.config('invoice_scanning.max_files', 10),
            'documents.*' => 'required|file|mimes:pdf,jpg,jpeg,png|max:'.$max,
        ]);
        $totalBytes = collect($request->file('documents'))->sum(fn ($file) => (int) $file->getSize());
        if ($totalBytes > ((int) config('invoice_scanning.max_total_file_kb', 30720) * 1024)) {
            return back()->withInput()->withErrors(['documents' => 'The combined invoice upload is too large. Upload fewer or smaller pages.']);
        }
        $businessId = (int) $request->session()->get('user.business_id');
        $location = BusinessLocation::where('business_id', $businessId)->findOrFail($request->integer('location_id'));
        abort_unless($this->canUseLocation($location->id), 403);

        $uuid = (string) Str::uuid();
        $hashes = [];
        $stored = [];
        $scan = null;
        try {
            foreach ($request->file('documents') as $index => $file) {
                $hash = hash_file('sha256', $file->getRealPath());
                $path = Storage::disk(config('invoice_scanning.disk'))->putFileAs(
                    $businessId.'/'.$uuid,
                    $file,
                    str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'-'.$hash.'.'.$file->extension()
                );
                $stored[] = compact('path', 'file', 'hash', 'index');
                $hashes[] = $hash;
            }

            $documentHash = hash('sha256', implode('|', $hashes));
            if (InvoiceScan::where('business_id', $businessId)->where('document_hash', $documentHash)->exists()) {
                foreach ($stored as $item) Storage::disk(config('invoice_scanning.disk'))->delete($item['path']);
                return back()->withInput()->withErrors(['documents' => 'This exact invoice document has already been uploaded.']);
            }

            $scan = InvoiceScan::create([
                'uuid' => $uuid,
                'business_id' => $businessId,
                'location_id' => $location->id,
                'created_by' => auth()->id(),
                'status' => 'uploaded',
                'document_hash' => $documentHash,
            ]);
            foreach ($stored as $item) {
                InvoiceScanDocument::create([
                    'invoice_scan_id' => $scan->id,
                    'disk' => config('invoice_scanning.disk'),
                    'path' => $item['path'],
                    'original_name' => $item['file']->getClientOriginalName(),
                    'mime_type' => $item['file']->getMimeType(),
                    'size_bytes' => $item['file']->getSize(),
                    'sha256' => $item['hash'],
                    'page_order' => $item['index'] + 1,
                ]);
            }
        } catch (Throwable $e) {
            $scan?->delete();
            foreach ($stored as $item) Storage::disk(config('invoice_scanning.disk'))->delete($item['path']);
            throw $e;
        }

        if (config('invoice_scanning.enabled')) {
            $scan->update(['status' => 'queued']);
            try { ProcessInvoiceScan::dispatch($scan->id); }
            catch (Throwable $e) { /* the job records a safe failure state */ }
        }

        return redirect()->route('invoice-scans.show', $scan->uuid)->with('status', [
            'success' => 1,
            'msg' => config('invoice_scanning.enabled') ? 'Invoice uploaded for scanning.' : 'Invoice saved. Configure invoice scanning before processing it.',
        ]);
    }

    public function show(Request $request, string $uuid)
    {
        $this->authorizeCreate();
        $scan = $this->scan($request, $uuid)->load(['documents', 'lines.variation.product', 'supplier', 'location', 'purchase']);
        $suppliers = Contact::contactDropdown($scan->business_id, true, true, false);
        $locations = BusinessLocation::forDropdown($scan->business_id, false, false);
        $permittedLocations = auth()->user()->permitted_locations();
        $purchaseOrderLines = PurchaseLine::query()
            ->with(['transaction', 'product', 'variations'])
            ->whereHas('transaction', function ($query) use ($scan, $permittedLocations) {
                $query->where('business_id', $scan->business_id)->where('type', 'purchase_order')->whereNotIn('status', ['completed', 'cancelled']);
                if ($permittedLocations !== 'all') $query->whereIn('location_id', $permittedLocations);
                if ($scan->supplier_id) $query->where('contact_id', $scan->supplier_id);
                if ($scan->location_id) $query->where('location_id', $scan->location_id);
            })
            ->whereColumn('po_quantity_purchased', '<', 'quantity')
            ->get()->mapWithKeys(function ($line) {
                $remaining = max(0, (float) $line->quantity - (float) $line->po_quantity_purchased);
                return [$line->id => ($line->transaction->ref_no ?: '#'.$line->transaction_id).' · '.$line->product->name.' · remaining '.$remaining];
            });
        return view('invoice_scans.show', compact('scan', 'suppliers', 'locations', 'purchaseOrderLines'));
    }

    public function process(Request $request, string $uuid)
    {
        $this->authorizeCreate();
        abort_unless(config('invoice_scanning.enabled'), 422, 'Invoice scanning is not configured.');
        $scan = $this->scan($request, $uuid);
        abort_if($scan->status === 'posted', 409, 'A posted invoice cannot be processed again.');
        if (in_array($scan->status, ['queued', 'processing'], true)) {
            return back()->with('status', ['success' => 1, 'msg' => 'Invoice processing is already in progress.']);
        }
        $scan->update(['status' => 'queued']);
        try { ProcessInvoiceScan::dispatch($scan->id); }
        catch (Throwable $e) { return back()->withErrors(['scan' => $scan->fresh()->failure_message ?: 'Invoice processing failed.']); }
        return back()->with('status', ['success' => 1, 'msg' => 'Invoice scan completed. Review the highlighted fields.']);
    }

    public function update(Request $request, string $uuid, InvoiceAmounts $amounts)
    {
        $this->authorizeCreate();
        $scan = $this->scan($request, $uuid);
        abort_if($scan->status === 'posted', 409, 'A posted invoice cannot be changed.');
        abort_if(in_array($scan->status, ['queued', 'processing'], true), 409, 'Wait for invoice processing to finish before reviewing it.');
        $data = $this->reviewData($request);
        if (!$amounts->isBalanced((float) $data['subtotal'], (float) ($data['discount_total'] ?? 0), (float) $data['tax_total'], (float) ($data['freight_total'] ?? 0), (float) $data['invoice_total'])) {
            return back()->withInput()->withErrors(['invoice_total' => 'Invoice totals do not balance: subtotal minus discount plus VAT and freight must equal the invoice total.']);
        }
        foreach ($data['lines'] as $line) {
            if (!empty($line['price_change_approved']) && !isset($line['proposed_sell_price'])) {
                return back()->withInput()->withErrors(['lines' => 'A proposed selling price is required when applying a price change.']);
            }
            if (!empty($line['price_change_approved']) && empty(trim((string) ($line['price_change_reason'] ?? '')))) {
                return back()->withInput()->withErrors(['lines' => 'A reason is required for every approved selling-price change.']);
            }
            if (!empty($line['price_change_approved']) && !auth()->user()->can('product.update')) {
                abort(403, 'Product price approval requires product update permission.');
            }
            if (!$amounts->isLineBalanced((float)$line['quantity'],(float)$line['unit_price'],(float)$line['tax_rate'],!empty($line['price_includes_tax']),(float)$line['line_total'])) {
                return back()->withInput()->withErrors(['lines' => 'One or more invoice line totals do not match quantity multiplied by unit price.']);
            }
        }
        $lineSubtotal = collect($data['lines'])->sum(fn ($line) => (float) $line['line_total']);
        if (abs($lineSubtotal - (float) $data['subtotal']) > max(0.05, abs($lineSubtotal) * 0.002)) {
            return back()->withInput()->withErrors(['subtotal' => 'The invoice lines do not add up to the reviewed subtotal.']);
        }
        $duplicate = \App\Transaction::where('business_id', $scan->business_id)->where('type', 'purchase')
            ->where('contact_id', $data['supplier_id'])->where('ref_no', $data['invoice_number'])->exists();
        if ($duplicate) return back()->withInput()->withErrors(['invoice_number' => 'This supplier invoice number already exists.']);
        $duplicateScan = InvoiceScan::where('business_id', $scan->business_id)->where('supplier_id', $data['supplier_id'])
            ->where('invoice_number', $data['invoice_number'])->where('id', '!=', $scan->id)->exists();
        if ($duplicateScan) return back()->withInput()->withErrors(['invoice_number' => 'This supplier invoice number is already present in another scan.']);
        Contact::where('business_id', $scan->business_id)->whereIn('type', ['supplier', 'both'])->findOrFail($data['supplier_id']);
        BusinessLocation::where('business_id', $scan->business_id)->findOrFail($data['location_id']);
        abort_unless($this->canUseLocation((int) $data['location_id']), 403);

        DB::transaction(function () use ($scan, $data) {
            $lockedScan = InvoiceScan::whereKey($scan->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedScan->status === 'posted', 409, 'A posted invoice cannot be changed.');
            abort_if(in_array($lockedScan->status, ['queued', 'processing'], true), 409, 'Wait for invoice processing to finish before reviewing it.');
            foreach ($data['lines'] as $id => $lineData) {
                $line = $lockedScan->lines()->whereKey($id)->lockForUpdate()->firstOrFail();
                \App\Variation::whereKey($lineData['variation_id'])->whereHas('product', fn ($q) => $q->where('business_id', $lockedScan->business_id))->firstOrFail();
                $lineData['price_approved_by'] = !empty($lineData['price_change_approved']) ? auth()->id() : null;
                $line->update($lineData + ['match_method' => 'reviewed', 'match_confidence' => 1]);
            }
            $lockedScan->update([
                'supplier_id' => $data['supplier_id'], 'location_id' => $data['location_id'],
                'invoice_number' => $data['invoice_number'], 'invoice_date' => $data['invoice_date'],
                'subtotal' => $data['subtotal'], 'discount_total' => $data['discount_total'] ?? 0, 'tax_total' => $data['tax_total'],
                'freight_total' => $data['freight_total'], 'invoice_total' => $data['invoice_total'],
                'status' => 'ready', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(),
            ]);
        });
        return back()->with('status', ['success' => 1, 'msg' => 'Invoice review saved.']);
    }

    public function post(Request $request, string $uuid, PostScannedPurchase $poster)
    {
        $this->authorizeCreate();
        $scan = $this->scan($request, $uuid);
        try {
            $purchase = $poster->post($scan, auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['post' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);
            return back()->withErrors(['post' => 'The purchase could not be created. No stock or pricing changes were retained.']);
        }
        return redirect('/purchases/'.$purchase->id)->with('status', ['success' => 1, 'msg' => 'Invoice approved, stock received and purchase created.']);
    }

    public function document(Request $request, string $uuid, int $document)
    {
        $this->authorizeCreate();
        $scan = $this->scan($request, $uuid);
        $file = $scan->documents()->findOrFail($document);
        return response(Storage::disk($file->disk)->get($file->path), 200, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'inline; filename="'.addcslashes(basename($file->original_name), '"\\').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function reviewData(Request $request): array
    {
        return $request->validate([
            'supplier_id' => 'required|integer', 'location_id' => 'required|integer',
            'invoice_number' => 'required|string|max:120', 'invoice_date' => 'required|date',
            'subtotal' => 'required|numeric|min:0', 'discount_total' => 'nullable|numeric|min:0', 'tax_total' => 'required|numeric|min:0',
            'freight_total' => 'nullable|numeric|min:0', 'invoice_total' => 'required|numeric|min:0.01',
            'lines' => 'required|array|min:1', 'lines.*.variation_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|min:0.0001', 'lines.*.pack_size' => 'required|numeric|min:0.0001',
            'lines.*.unit_price' => 'required|numeric|min:0', 'lines.*.tax_rate' => 'required|numeric|min:0|max:100',
            'lines.*.line_total' => 'required|numeric|min:0', 'lines.*.proposed_sell_price' => 'nullable|numeric|min:0',
            'lines.*.purchase_order_line_id' => 'nullable|integer',
            'lines.*.price_change_reason' => 'nullable|string|max:255',
            'lines.*.price_includes_tax' => 'sometimes|boolean', 'lines.*.price_change_approved' => 'sometimes|boolean',
        ]);
    }

    private function scan(Request $request, string $uuid): InvoiceScan
    {
        return InvoiceScan::where('business_id', (int) $request->session()->get('user.business_id'))->where('uuid', $uuid)->firstOrFail();
    }

    private function authorizeCreate(): void
    {
        abort_unless(auth()->user()?->can('purchase.create'), 403, 'Unauthorized action.');
    }

    private function canUseLocation(int $locationId): bool
    {
        $locations = auth()->user()->permitted_locations();
        return $locations === 'all' || in_array($locationId, array_map('intval', $locations), true);
    }
}
