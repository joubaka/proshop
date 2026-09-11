<?php
namespace App\Lights\Shelly;

final class PilotApproval
{
    public static function path(int $channel): string
    {
        return base_path('.local-acceptance/private/shelly/pilot-approval-'.$channel.'.json');
    }

    public static function allows(int $channel): bool
    {
        if (!config('lights.control.live_enabled') || !app()->environment('acceptance')) { return false; }
        $path = self::path($channel);
        if (!is_file($path)) { return false; }
        $data = json_decode(file_get_contents($path), true);
        $now = now()->getTimestamp();
        return is_array($data) && ($data['device'] ?? null) === PrivateSettings::DEVICE
            && ($data['channel'] ?? null) === $channel
            && is_int($data['expires_at'] ?? null) && $data['expires_at'] >= $now && $data['expires_at'] <= $now + 900
            && ($data['onsite_empty_court_confirmed'] ?? false) === true
            && ($data['reboot_off_and_overrides_checked'] ?? false) === true;
    }
}
