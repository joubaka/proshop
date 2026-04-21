<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

include_once('install_r.php');

Route::middleware(['setData'])->group(function () {
    Route::get('/', function () {
        return view('welcome');
    });

    Auth::routes();

    Route::get('/business/register', 'App\Http\Controllers\BusinessController@getRegister')->name('business.getRegister');
    Route::post('/business/register', 'App\Http\Controllers\BusinessController@postRegister')->name('business.postRegister');
    Route::post('/business/register/check-username', 'App\Http\Controllers\BusinessController@postCheckUsername')->name('business.postCheckUsername');
    Route::post('/business/register/check-email', 'App\Http\Controllers\BusinessController@postCheckEmail')->name('business.postCheckEmail');

    Route::get('/invoice/{token}', 'App\Http\Controllers\SellPosController@showInvoice')
        ->name('show_invoice');
    Route::get('/quote/{token}', 'App\Http\Controllers\SellPosController@showInvoice')
        ->name('show_quote');

    Route::get('/pay/{token}', 'App\Http\Controllers\SellPosController@invoicePayment')
        ->name('invoice_payment');
    Route::post('/confirm-payment/{id}', 'App\Http\Controllers\SellPosController@confirmPayment')
        ->name('confirm_payment');
});

//Routes for authenticated users only
Route::middleware(['setData', 'auth', 'SetSessionData', 'language', 'timezone', 'AdminSidebarMenu', 'CheckUserLogin'])->group(function () {
    Route::get('/home', 'App\Http\Controllers\HomeController@index')->name('home');
    Route::get('/home/get-totals', 'App\Http\Controllers\HomeController@getTotals');
    Route::get('/home/product-stock-alert', 'App\Http\Controllers\HomeController@getProductStockAlert');
    Route::get('/home/purchase-payment-dues', 'App\Http\Controllers\HomeController@getPurchasePaymentDues');
    Route::get('/home/sales-payment-dues', 'App\Http\Controllers\HomeController@getSalesPaymentDues');
    Route::post('/attach-medias-to-model', 'App\Http\Controllers\HomeController@attachMediasToGivenModel')->name('attach.medias.to.model');
    Route::get('/calendar', 'App\Http\Controllers\HomeController@getCalendar')->name('calendar');
    
    Route::post('/test-email', 'App\Http\Controllers\BusinessController@testEmailConfiguration');
    Route::post('/test-sms', 'App\Http\Controllers\BusinessController@testSmsConfiguration');
    Route::get('/business/settings', 'App\Http\Controllers\BusinessController@getBusinessSettings')->name('business.getBusinessSettings');
    Route::post('/business/update', 'App\Http\Controllers\BusinessController@postBusinessSettings')->name('business.postBusinessSettings');
    Route::get('/user/profile', 'App\Http\Controllers\UserController@getProfile')->name('user.getProfile');
    Route::post('/user/update', 'App\Http\Controllers\UserController@updateProfile')->name('user.updateProfile');
    Route::post('/user/update-password', 'App\Http\Controllers\UserController@updatePassword')->name('user.updatePassword');

    Route::resource('brands', \App\Http\Controllers\BrandController::class);
    
    Route::resource('payment-account', \App\Http\Controllers\PaymentAccountController::class);

    Route::resource('tax-rates', \App\Http\Controllers\TaxRateController::class);

    Route::resource('units', \App\Http\Controllers\UnitController::class);

    Route::post('check-mobile', 'App\Http\Controllers\ContactController@checkMobile');
    Route::get('/get-contact-due/{contact_id}', 'App\Http\Controllers\ContactController@getContactDue');
    Route::get('/contacts/payments/{contact_id}', 'App\Http\Controllers\ContactController@getContactPayments');
    Route::get('/contacts/map', 'App\Http\Controllers\ContactController@contactMap');
    Route::get('/contacts/update-status/{id}', 'App\Http\Controllers\ContactController@updateStatus');
    Route::get('/contacts/stock-report/{supplier_id}', 'App\Http\Controllers\ContactController@getSupplierStockReport');
    Route::get('/contacts/ledger', 'App\Http\Controllers\ContactController@getLedger');
    Route::post('/contacts/send-ledger', 'App\Http\Controllers\ContactController@sendLedger');
    Route::get('/contacts/import', 'App\Http\Controllers\ContactController@getImportContacts')->name('contacts.import');
    Route::post('/contacts/import', 'App\Http\Controllers\ContactController@postImportContacts');
    Route::post('/contacts/check-contacts-id', 'App\Http\Controllers\ContactController@checkContactId');
    Route::get('/contacts/customers', 'App\Http\Controllers\ContactController@getCustomers');
    Route::resource('contacts', \App\Http\Controllers\ContactController::class);

    Route::get('taxonomies-ajax-index-page', 'App\Http\Controllers\TaxonomyController@getTaxonomyIndexPage');
    Route::resource('taxonomies', \App\Http\Controllers\TaxonomyController::class);

    Route::resource('variation-templates', \App\Http\Controllers\VariationTemplateController::class);

    Route::get('/products/stock-history/{id}', 'App\Http\Controllers\ProductController@productStockHistory');
    Route::get('/delete-media/{media_id}', 'App\Http\Controllers\ProductController@deleteMedia');
    Route::post('/products/mass-deactivate', 'App\Http\Controllers\ProductController@massDeactivate');
    Route::get('/products/activate/{id}', 'App\Http\Controllers\ProductController@activate');
    Route::get('/products/view-product-group-price/{id}', 'App\Http\Controllers\ProductController@viewGroupPrice');
    Route::get('/products/add-selling-prices/{id}', 'App\Http\Controllers\ProductController@addSellingPrices');
    Route::post('/products/save-selling-prices', 'App\Http\Controllers\ProductController@saveSellingPrices');
    Route::post('/products/mass-delete', 'App\Http\Controllers\ProductController@massDestroy');
    Route::get('/products/view/{id}', 'App\Http\Controllers\ProductController@view');
    Route::get('/products/list', 'App\Http\Controllers\ProductController@getProducts');
    Route::get('/products/list-no-variation', 'App\Http\Controllers\ProductController@getProductsWithoutVariations');
    Route::post('/products/bulk-edit', 'App\Http\Controllers\ProductController@bulkEdit');
    Route::post('/products/bulk-update', 'App\Http\Controllers\ProductController@bulkUpdate');
    Route::post('/products/bulk-update-location', 'App\Http\Controllers\ProductController@updateProductLocation');
    Route::get('/products/get-product-to-edit/{product_id}', 'App\Http\Controllers\ProductController@getProductToEdit');
    
    Route::post('/products/get_sub_categories', 'App\Http\Controllers\ProductController@getSubCategories');
    Route::get('/products/get_sub_units', 'App\Http\Controllers\ProductController@getSubUnits');
    Route::post('/products/product_form_part', 'App\Http\Controllers\ProductController@getProductVariationFormPart');
    Route::post('/products/get_product_variation_row', 'App\Http\Controllers\ProductController@getProductVariationRow');
    Route::post('/products/get_variation_template', 'App\Http\Controllers\ProductController@getVariationTemplate');
    Route::get('/products/get_variation_value_row', 'App\Http\Controllers\ProductController@getVariationValueRow');
    Route::post('/products/check_product_sku', 'App\Http\Controllers\ProductController@checkProductSku');
    Route::get('/products/quick_add', 'App\Http\Controllers\ProductController@quickAdd');
    Route::post('/products/save_quick_product', 'App\Http\Controllers\ProductController@saveQuickProduct');
    Route::get('/products/get-combo-product-entry-row', 'App\Http\Controllers\ProductController@getComboProductEntryRow');
    Route::post('/products/toggle-woocommerce-sync', 'App\Http\Controllers\ProductController@toggleWooCommerceSync');
    
    Route::resource('products', \App\Http\Controllers\ProductController::class);

    Route::post('/import-purchase-products', 'App\Http\Controllers\PurchaseController@importPurchaseProducts');
    Route::post('/purchases/update-status', 'App\Http\Controllers\PurchaseController@updateStatus');
    Route::get('/purchases/get_products', 'App\Http\Controllers\PurchaseController@getProducts');
    Route::get('/purchases/get_suppliers', 'App\Http\Controllers\PurchaseController@getSuppliers');
    Route::post('/purchases/get_purchase_entry_row', 'App\Http\Controllers\PurchaseController@getPurchaseEntryRow');
    Route::post('/purchases/check_ref_number', 'App\Http\Controllers\PurchaseController@checkRefNumber');
    Route::resource('purchases', \App\Http\Controllers\PurchaseController::class)->except(['show']);

    Route::get('/toggle-subscription/{id}', 'App\Http\Controllers\SellPosController@toggleRecurringInvoices');
    Route::post('/sells/pos/get-types-of-service-details', 'App\Http\Controllers\SellPosController@getTypesOfServiceDetails');
    Route::get('/sells/subscriptions', 'App\Http\Controllers\SellPosController@listSubscriptions');
    Route::get('/sells/duplicate/{id}', 'App\Http\Controllers\SellController@duplicateSell');
    Route::get('/sells/drafts', 'App\Http\Controllers\SellController@getDrafts');
    Route::get('/sells/convert-to-draft/{id}', 'App\Http\Controllers\SellPosController@convertToInvoice');
    Route::get('/sells/convert-to-proforma/{id}', 'App\Http\Controllers\SellPosController@convertToProforma');
    Route::get('/sells/quotations', 'App\Http\Controllers\SellController@getQuotations');
    Route::get('/sells/draft-dt', 'App\Http\Controllers\SellController@getDraftDatables');
    Route::resource('sells', \App\Http\Controllers\SellController::class)->except(['show']);

    Route::get('/import-sales', 'App\Http\Controllers\ImportSalesController@index');
    Route::post('/import-sales/preview', 'App\Http\Controllers\ImportSalesController@preview');
    Route::post('/import-sales', 'App\Http\Controllers\ImportSalesController@import');
    Route::get('/revert-sale-import/{batch}', 'App\Http\Controllers\ImportSalesController@revertSaleImport');

    Route::get('/sells/pos/get_product_row/{variation_id}/{location_id}', 'App\Http\Controllers\SellPosController@getProductRow');
    Route::post('/sells/pos/get_payment_row', 'App\Http\Controllers\SellPosController@getPaymentRow');
    Route::post('/sells/pos/get-reward-details', 'App\Http\Controllers\SellPosController@getRewardDetails');
    Route::get('/sells/pos/get-recent-transactions', 'App\Http\Controllers\SellPosController@getRecentTransactions');
    Route::get('/sells/pos/get-product-suggestion', 'App\Http\Controllers\SellPosController@getProductSuggestion');
    Route::get('/sells/pos/get-featured-products/{location_id}', 'App\Http\Controllers\SellPosController@getFeaturedProducts');
    Route::get('/reset-mapping', 'App\Http\Controllers\SellController@resetMapping');

    Route::resource('pos', \App\Http\Controllers\SellPosController::class);

    Route::resource('roles', \App\Http\Controllers\RoleController::class);

    Route::resource('users', \App\Http\Controllers\ManageUserController::class);

    Route::resource('group-taxes', \App\Http\Controllers\GroupTaxController::class);

    Route::get('/barcodes/set_default/{id}', 'App\Http\Controllers\BarcodeController@setDefault');
    Route::resource('barcodes', \App\Http\Controllers\BarcodeController::class);

    //Invoice schemes..
    Route::get('/invoice-schemes/set_default/{id}', 'App\Http\Controllers\InvoiceSchemeController@setDefault');
    Route::resource('invoice-schemes', \App\Http\Controllers\InvoiceSchemeController::class);

    //Print Labels
    Route::get('/labels/show', 'App\Http\Controllers\LabelsController@show');
    Route::get('/labels/add-product-row', 'App\Http\Controllers\LabelsController@addProductRow');
    Route::get('/labels/preview', 'App\Http\Controllers\LabelsController@preview');

    //Reports...
    Route::get('/reports/get-stock-by-sell-price', 'App\Http\Controllers\ReportController@getStockBySellingPrice');
    Route::get('/reports/purchase-report', 'App\Http\Controllers\ReportController@purchaseReport');
    Route::get('/reports/sale-report', 'App\Http\Controllers\ReportController@saleReport');
    Route::get('/reports/service-staff-report', 'App\Http\Controllers\ReportController@getServiceStaffReport');
    Route::get('/reports/service-staff-line-orders', 'App\Http\Controllers\ReportController@serviceStaffLineOrders');
    Route::get('/reports/table-report', 'App\Http\Controllers\ReportController@getTableReport');
    Route::get('/reports/profit-loss', 'App\Http\Controllers\ReportController@getProfitLoss');
    Route::get('/reports/get-opening-stock', 'App\Http\Controllers\ReportController@getOpeningStock');
    Route::get('/reports/purchase-sell', 'App\Http\Controllers\ReportController@getPurchaseSell');
    Route::get('/reports/customer-supplier', 'App\Http\Controllers\ReportController@getCustomerSuppliers');
    Route::get('/reports/stock-report', 'App\Http\Controllers\ReportController@getStockReport');
    Route::get('/reports/stock-details', 'App\Http\Controllers\ReportController@getStockDetails');
    Route::get('/reports/tax-report', 'App\Http\Controllers\ReportController@getTaxReport');
    Route::get('/reports/tax-details', 'App\Http\Controllers\ReportController@getTaxDetails');
    Route::get('/reports/trending-products', 'App\Http\Controllers\ReportController@getTrendingProducts');
    Route::get('/reports/expense-report', 'App\Http\Controllers\ReportController@getExpenseReport');
    Route::get('/reports/stock-adjustment-report', 'App\Http\Controllers\ReportController@getStockAdjustmentReport');
    Route::get('/reports/register-report', 'App\Http\Controllers\ReportController@getRegisterReport');
    Route::get('/reports/sales-representative-report', 'App\Http\Controllers\ReportController@getSalesRepresentativeReport');
    Route::get('/reports/sales-representative-total-expense', 'App\Http\Controllers\ReportController@getSalesRepresentativeTotalExpense');
    Route::get('/reports/sales-representative-total-sell', 'App\Http\Controllers\ReportController@getSalesRepresentativeTotalSell');
    Route::get('/reports/sales-representative-total-commission', 'App\Http\Controllers\ReportController@getSalesRepresentativeTotalCommission');
    Route::get('/reports/stock-expiry', 'App\Http\Controllers\ReportController@getStockExpiryReport');
    Route::get('/reports/stock-expiry-edit-modal/{purchase_line_id}', 'App\Http\Controllers\ReportController@getStockExpiryReportEditModal');
    Route::post('/reports/stock-expiry-update', 'App\Http\Controllers\ReportController@updateStockExpiryReport')->name('updateStockExpiryReport');
    Route::get('/reports/customer-group', 'App\Http\Controllers\ReportController@getCustomerGroup');
    Route::get('/reports/product-purchase-report', 'App\Http\Controllers\ReportController@getproductPurchaseReport');
    Route::get('/reports/product-sell-grouped-by', 'App\Http\Controllers\ReportController@productSellReportBy');
    Route::get('/reports/product-sell-report', 'App\Http\Controllers\ReportController@getproductSellReport');
    Route::get('/reports/product-sell-report-with-purchase', 'App\Http\Controllers\ReportController@getproductSellReportWithPurchase');
    Route::get('/reports/product-sell-grouped-report', 'App\Http\Controllers\ReportController@getproductSellGroupedReport');
    Route::get('/reports/lot-report', 'App\Http\Controllers\ReportController@getLotReport');
    Route::get('/reports/purchase-payment-report', 'App\Http\Controllers\ReportController@purchasePaymentReport');
    Route::get('/reports/sell-payment-report', 'App\Http\Controllers\ReportController@sellPaymentReport');
    Route::get('/reports/product-stock-details', 'App\Http\Controllers\ReportController@productStockDetails');
    Route::get('/reports/adjust-product-stock', 'App\Http\Controllers\ReportController@adjustProductStock');
    Route::get('/reports/get-profit/{by?}', 'App\Http\Controllers\ReportController@getProfit');
    Route::get('/reports/items-report', 'App\Http\Controllers\ReportController@itemsReport');
    Route::get('/reports/get-stock-value', 'App\Http\Controllers\ReportController@getStockValue');
    
    Route::get('business-location/activate-deactivate/{location_id}', 'App\Http\Controllers\BusinessLocationController@activateDeactivateLocation');

    //Business Location Settings...
    Route::prefix('business-location/{location_id}')->name('location.')->group(function () {
        Route::get('settings', 'App\Http\Controllers\LocationSettingsController@index')->name('settings');
        Route::post('settings', 'App\Http\Controllers\LocationSettingsController@updateSettings')->name('settings_update');
    });

    //Business Locations...
    Route::post('business-location/check-location-id', 'App\Http\Controllers\BusinessLocationController@checkLocationId');
    Route::resource('business-location', \App\Http\Controllers\BusinessLocationController::class);

    //Invoice layouts..
    Route::resource('invoice-layouts', \App\Http\Controllers\InvoiceLayoutController::class);

    Route::post('get-expense-sub-categories', 'App\Http\Controllers\ExpenseCategoryController@getSubCategories');

    //Expense Categories...
    Route::resource('expense-categories', \App\Http\Controllers\ExpenseCategoryController::class);

    //Expenses...
    Route::resource('expenses', \App\Http\Controllers\ExpenseController::class);

    //Transaction payments...
    // Route::get('/payments/opening-balance/{contact_id}', 'App\Http\Controllers\TransactionPaymentController@getOpeningBalancePayments');
    Route::get('/payments/show-child-payments/{payment_id}', 'App\Http\Controllers\TransactionPaymentController@showChildPayments');
    Route::get('/payments/view-payment/{payment_id}', 'App\Http\Controllers\TransactionPaymentController@viewPayment');
    Route::get('/payments/add_payment/{transaction_id}', 'App\Http\Controllers\TransactionPaymentController@addPayment');
    Route::get('/payments/pay-contact-due/{contact_id}', 'App\Http\Controllers\TransactionPaymentController@getPayContactDue');
    Route::post('/payments/pay-contact-due', 'App\Http\Controllers\TransactionPaymentController@postPayContactDue');
    Route::resource('payments', \App\Http\Controllers\TransactionPaymentController::class);

    //Printers...
    Route::resource('printers', \App\Http\Controllers\PrinterController::class);

    Route::get('/stock-adjustments/remove-expired-stock/{purchase_line_id}', 'App\Http\Controllers\StockAdjustmentController@removeExpiredStock');
    Route::post('/stock-adjustments/get_product_row', 'App\Http\Controllers\StockAdjustmentController@getProductRow');
    Route::resource('stock-adjustments', \App\Http\Controllers\StockAdjustmentController::class);

    Route::get('/cash-register/register-details', 'App\Http\Controllers\CashRegisterController@getRegisterDetails');
    Route::get('/cash-register/close-register/{id?}', 'App\Http\Controllers\CashRegisterController@getCloseRegister');
    Route::post('/cash-register/close-register', 'App\Http\Controllers\CashRegisterController@postCloseRegister');
    Route::resource('cash-register', \App\Http\Controllers\CashRegisterController::class);

    //Import products
    Route::get('/import-products', 'App\Http\Controllers\ImportProductsController@index');
    Route::post('/import-products/store', 'App\Http\Controllers\ImportProductsController@store');

    //Sales Commission Agent
    Route::resource('sales-commission-agents', \App\Http\Controllers\SalesCommissionAgentController::class);

    //Stock Transfer
    Route::get('stock-transfers/print/{id}', 'App\Http\Controllers\StockTransferController@printInvoice');
    Route::post('stock-transfers/update-status/{id}', 'App\Http\Controllers\StockTransferController@updateStatus');
    Route::resource('stock-transfers', \App\Http\Controllers\StockTransferController::class);
    
    Route::get('/opening-stock/add/{product_id}', 'App\Http\Controllers\OpeningStockController@add');
    Route::post('/opening-stock/save', 'App\Http\Controllers\OpeningStockController@save');

    //Customer Groups
    Route::resource('customer-group', \App\Http\Controllers\CustomerGroupController::class);

    //Import opening stock
    Route::get('/import-opening-stock', 'App\Http\Controllers\ImportOpeningStockController@index');
    Route::post('/import-opening-stock/store', 'App\Http\Controllers\ImportOpeningStockController@store');

    //Sell return
    Route::resource('sell-return', \App\Http\Controllers\SellReturnController::class);
    Route::get('sell-return/get-product-row', 'App\Http\Controllers\SellReturnController@getProductRow');
    Route::get('/sell-return/print/{id}', 'App\Http\Controllers\SellReturnController@printInvoice');
    Route::get('/sell-return/add/{id}', 'App\Http\Controllers\SellReturnController@add');
    
    //Backup
    Route::get('backup/download/{file_name}', 'App\Http\Controllers\BackUpController@download');
    Route::get('backup/delete/{file_name}', 'App\Http\Controllers\BackUpController@delete');
    Route::resource('backup', \App\Http\Controllers\BackUpController::class, ['only' => [
        'index', 'create', 'store'
    ]]);

    Route::get('selling-price-group/activate-deactivate/{id}', 'App\Http\Controllers\SellingPriceGroupController@activateDeactivate');
    Route::get('export-selling-price-group', 'App\Http\Controllers\SellingPriceGroupController@export');
    Route::post('import-selling-price-group', 'App\Http\Controllers\SellingPriceGroupController@import');

    Route::resource('selling-price-group', \App\Http\Controllers\SellingPriceGroupController::class);

    Route::resource('notification-templates', \App\Http\Controllers\NotificationTemplateController::class)->only(['index', 'store']);
    Route::get('notification/get-template/{transaction_id}/{template_for}', 'App\Http\Controllers\NotificationController@getTemplate');
    Route::post('notification/send', 'App\Http\Controllers\NotificationController@send');

    Route::post('/purchase-return/update', 'App\Http\Controllers\CombinedPurchaseReturnController@update');
    Route::get('/purchase-return/edit/{id}', 'App\Http\Controllers\CombinedPurchaseReturnController@edit');
    Route::post('/purchase-return/save', 'App\Http\Controllers\CombinedPurchaseReturnController@save');
    Route::post('/purchase-return/get_product_row', 'App\Http\Controllers\CombinedPurchaseReturnController@getProductRow');
    Route::get('/purchase-return/create', 'App\Http\Controllers\CombinedPurchaseReturnController@create');
    Route::get('/purchase-return/add/{id}', 'App\Http\Controllers\PurchaseReturnController@add');
    Route::resource('/purchase-return', \App\Http\Controllers\PurchaseReturnController::class, ['except' => ['create']]);

    Route::get('/discount/activate/{id}', 'App\Http\Controllers\DiscountController@activate');
    Route::post('/discount/mass-deactivate', 'App\Http\Controllers\DiscountController@massDeactivate');
    Route::resource('discount', \App\Http\Controllers\DiscountController::class);

    Route::group(['prefix' => 'account'], function () {
        Route::resource('/account', \App\Http\Controllers\AccountController::class);
        Route::get('/fund-transfer/{id}', 'App\Http\Controllers\AccountController@getFundTransfer');
        Route::post('/fund-transfer', 'App\Http\Controllers\AccountController@postFundTransfer');
        Route::get('/deposit/{id}', 'App\Http\Controllers\AccountController@getDeposit');
        Route::post('/deposit', 'App\Http\Controllers\AccountController@postDeposit');
        Route::get('/close/{id}', 'App\Http\Controllers\AccountController@close');
        Route::get('/activate/{id}', 'App\Http\Controllers\AccountController@activate');
        Route::get('/delete-account-transaction/{id}', 'App\Http\Controllers\AccountController@destroyAccountTransaction');
        Route::get('/edit-account-transaction/{id}', 'App\Http\Controllers\AccountController@editAccountTransaction');
        Route::post('/update-account-transaction/{id}', 'App\Http\Controllers\AccountController@updateAccountTransaction');
        Route::get('/get-account-balance/{id}', 'App\Http\Controllers\AccountController@getAccountBalance');
        Route::get('/balance-sheet', 'App\Http\Controllers\AccountReportsController@balanceSheet');
        Route::get('/trial-balance', 'App\Http\Controllers\AccountReportsController@trialBalance');
        Route::get('/payment-account-report', 'App\Http\Controllers\AccountReportsController@paymentAccountReport');
        Route::get('/link-account/{id}', 'App\Http\Controllers\AccountReportsController@getLinkAccount');
        Route::post('/link-account', 'App\Http\Controllers\AccountReportsController@postLinkAccount');
        Route::get('/cash-flow', 'App\Http\Controllers\AccountController@cashFlow');
    });
    
    Route::resource('account-types', \App\Http\Controllers\AccountTypeController::class);

    //Restaurant module
    Route::group(['prefix' => 'modules'], function () {
        Route::resource('tables', \App\Http\Controllers\Restaurant\TableController::class);
        Route::resource('modifiers', \App\Http\Controllers\Restaurant\ModifierSetsController::class);

        //Map modifier to products
        Route::get('/product-modifiers/{id}/edit', 'App\Http\Controllers\Restaurant\ProductModifierSetController@edit');
        Route::post('/product-modifiers/{id}/update', 'App\Http\Controllers\Restaurant\ProductModifierSetController@update');
        Route::get('/product-modifiers/product-row/{product_id}', 'App\Http\Controllers\Restaurant\ProductModifierSetController@product_row');

        Route::get('/add-selected-modifiers', 'App\Http\Controllers\Restaurant\ProductModifierSetController@add_selected_modifiers');

        Route::get('/kitchen', 'App\Http\Controllers\Restaurant\KitchenController@index');
        Route::get('/kitchen/mark-as-cooked/{id}', 'App\Http\Controllers\Restaurant\KitchenController@markAsCooked');
        Route::post('/refresh-orders-list', 'App\Http\Controllers\Restaurant\KitchenController@refreshOrdersList');
        Route::post('/refresh-line-orders-list', 'App\Http\Controllers\Restaurant\KitchenController@refreshLineOrdersList');

        Route::get('/orders', 'App\Http\Controllers\Restaurant\OrderController@index');
        Route::get('/orders/mark-as-served/{id}', 'App\Http\Controllers\Restaurant\OrderController@markAsServed');
        Route::get('/data/get-pos-details', 'App\Http\Controllers\Restaurant\DataController@getPosDetails');
        Route::get('/orders/mark-line-order-as-served/{id}', 'App\Http\Controllers\Restaurant\OrderController@markLineOrderAsServed');
        Route::get('/print-line-order', 'App\Http\Controllers\Restaurant\OrderController@printLineOrder');
    });

    Route::get('bookings/get-todays-bookings', 'App\Http\Controllers\Restaurant\BookingController@getTodaysBookings');
    Route::resource('bookings', \App\Http\Controllers\Restaurant\BookingController::class);
    
    Route::resource('types-of-service', \App\Http\Controllers\TypesOfServiceController::class);
    Route::get('sells/edit-shipping/{id}', 'App\Http\Controllers\SellController@editShipping');
    Route::put('sells/update-shipping/{id}', 'App\Http\Controllers\SellController@updateShipping');
    Route::get('shipments', 'App\Http\Controllers\SellController@shipments');

    Route::post('upload-module', 'App\Http\Controllers\Install\ModulesController@uploadModule');
    Route::resource('manage-modules', \App\Http\Controllers\Install\ModulesController::class)
        ->only(['index', 'destroy', 'update']);
    Route::resource('warranties', \App\Http\Controllers\WarrantyController::class);

    Route::resource('dashboard-configurator', \App\Http\Controllers\DashboardConfiguratorController::class)
    ->only(['edit', 'update']);

    Route::get('view-media/{model_id}', 'App\Http\Controllers\SellController@viewMedia');

    //common controller for document & note
    Route::get('get-document-note-page', 'App\Http\Controllers\DocumentAndNoteController@getDocAndNoteIndexPage');
    Route::post('post-document-upload', 'App\Http\Controllers\DocumentAndNoteController@postMedia');
    Route::resource('note-documents', \App\Http\Controllers\DocumentAndNoteController::class);
    Route::resource('purchase-order', \App\Http\Controllers\PurchaseOrderController::class);
    Route::get('get-purchase-orders/{contact_id}', 'App\Http\Controllers\PurchaseOrderController@getPurchaseOrders');
    Route::get('get-purchase-order-lines/{purchase_order_id}', 'App\Http\Controllers\PurchaseController@getPurchaseOrderLines');
    Route::get('edit-purchase-orders/{id}/status', 'App\Http\Controllers\PurchaseOrderController@getEditPurchaseOrderStatus');
    Route::put('update-purchase-orders/{id}/status', 'App\Http\Controllers\PurchaseOrderController@postEditPurchaseOrderStatus');
    Route::resource('sales-order', \App\Http\Controllers\SalesOrderController::class)->only(['index']);
    Route::get('get-sales-orders/{customer_id}', 'App\Http\Controllers\SalesOrderController@getSalesOrders');
    Route::get('get-sales-order-lines', 'App\Http\Controllers\SellPosController@getSalesOrderLines');
    Route::get('edit-sales-orders/{id}/status', 'App\Http\Controllers\SalesOrderController@getEditSalesOrderStatus');
    Route::put('update-sales-orders/{id}/status', 'App\Http\Controllers\SalesOrderController@postEditSalesOrderStatus');
    Route::get('reports/activity-log', 'App\Http\Controllers\ReportController@activityLog');
    Route::get('user-location/{latlng}', 'App\Http\Controllers\HomeController@getUserLocation');
});


Route::middleware(['EcomApi'])->prefix('api/ecom')->group(function () {
    Route::get('products/{id?}', 'App\Http\Controllers\ProductController@getProductsApi');
    Route::get('categories', 'App\Http\Controllers\TaxonomyController@getCategoriesApi');
    Route::get('brands', 'App\Http\Controllers\BrandController@getBrandsApi');
    Route::post('customers', 'App\Http\Controllers\ContactController@postCustomersApi');
    Route::get('settings', 'App\Http\Controllers\BusinessController@getEcomSettings');
    Route::get('variations', 'App\Http\Controllers\ProductController@getVariationsApi');
    Route::post('orders', 'App\Http\Controllers\SellPosController@placeOrdersApi');
});

//common route
Route::middleware(['auth'])->group(function () {
    // logout is already registered by Auth::routes() above; no duplicate needed
});

Route::middleware(['setData', 'auth', 'SetSessionData', 'language', 'timezone'])->group(function () {
    Route::get('/load-more-notifications', 'App\Http\Controllers\HomeController@loadMoreNotifications');
    Route::get('/get-total-unread', 'App\Http\Controllers\HomeController@getTotalUnreadNotifications');
    Route::get('/purchases/print/{id}', 'App\Http\Controllers\PurchaseController@printInvoice');
    Route::get('/purchases/{id}', 'App\Http\Controllers\PurchaseController@show');
    Route::get('/download-purchase-order/{id}/pdf', 'App\Http\Controllers\PurchaseOrderController@downloadPdf')->name('purchaseOrder.downloadPdf');
    Route::get('/sells/{id}', 'App\Http\Controllers\SellController@show');
    Route::get('/sells/{transaction_id}/print', 'App\Http\Controllers\SellPosController@printInvoice')->name('sell.printInvoice');
    Route::get('/download-sells/{transaction_id}/pdf', 'App\Http\Controllers\SellPosController@downloadPdf')->name('sell.downloadPdf');
    Route::get('/download-quotation/{id}/pdf', 'App\Http\Controllers\SellPosController@downloadQuotationPdf')
        ->name('quotation.downloadPdf');
    Route::get('/download-packing-list/{id}/pdf', 'App\Http\Controllers\SellPosController@downloadPackingListPdf')
        ->name('packing.downloadPdf');
    Route::get('/sells/invoice-url/{id}', 'App\Http\Controllers\SellPosController@showInvoiceUrl');
    Route::get('/show-notification/{id}', 'App\Http\Controllers\HomeController@showNotification');
});