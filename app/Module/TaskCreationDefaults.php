<?php

namespace App\Module;

use App\Models\ProjectUser;
use App\Models\User;

class TaskCreationDefaults
{
    public static function assistant(int $projectId): ?int
    {
        $email = (string)config('dootask.task_default_assistant_email');
        if (!$email || $projectId <= 0) return null;
        $user = User::whereEmail($email)->where('bot', 0)->first();
        if (!$user || $user->isDisable(true)
            || !ProjectUser::whereProjectId($projectId)->whereUserid($user->userid)->exists()) {
            return null;
        }
        return (int)$user->userid;
    }

    public static function assistants(int $projectId, array $owners, ?array $explicit): array
    {
        if ($explicit !== null) return array_values(array_unique(array_diff($explicit, $owners)));
        $userid = self::assistant($projectId);
        return $userid && !in_array($userid, $owners) ? [$userid] : [];
    }
}
