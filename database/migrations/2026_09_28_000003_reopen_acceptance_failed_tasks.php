<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $items = DB::table('project_flow_items')
                ->where('name', '验收失败')->where('status', 'end')
                ->get(['id', 'name', 'color']);

            foreach ($items as $item) {
                DB::table('project_flow_items')->where('id', $item->id)
                    ->update(['status' => 'progress']);
                DB::table('project_tasks')->where('flow_item_id', $item->id)
                    ->update([
                        'flow_item_name' => "progress|{$item->name}|{$item->color}",
                        'complete_at' => null,
                    ]);
            }

            $setting = DB::table('settings')->where('name', 'columnTemplate')->first(['id', 'setting']);
            if (!$setting) {
                return;
            }
            $templates = json_decode($setting->setting, true);
            if (!is_array($templates)) {
                return;
            }
            $changed = false;
            foreach ($templates as &$template) {
                if (!is_array($template['config']['flow'] ?? null)) {
                    continue;
                }
                foreach ($template['config']['flow'] as &$flow) {
                    if (($flow['name'] ?? null) === '验收失败' && ($flow['status'] ?? null) === 'end') {
                        $flow['status'] = 'progress';
                        $changed = true;
                    }
                }
                unset($flow);
            }
            unset($template);
            if ($changed) {
                DB::table('settings')->where('id', $setting->id)->update([
                    'setting' => json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        // 原完成时间无法可靠还原，数据修复不做自动逆转。
    }
};
