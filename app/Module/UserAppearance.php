<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\Setting;
use App\Models\User;

class UserAppearance
{
    public static function get(User $user): array
    {
        $row = Setting::whereName('userAppearance_' . (int)$user->userid)->first();
        $data = $row ? Base::string2array($row->setting) : [];
        $size = $data['font_size'] ?? null;
        return ['font_size' => is_int($size) && $size >= 12 && $size <= 20 ? $size : null];
    }

    public static function save(User $user, mixed $size): array
    {
        // null 恢复系统默认；严格拒绝小数、布尔值与范围外的字号。
        if ($size !== null && (!is_int($size) || $size < 12 || $size > 20)) {
            throw new ApiException('参数错误');
        }
        Base::setting('userAppearance_' . (int)$user->userid, ['font_size' => $size]);
        return self::get($user);
    }
}
