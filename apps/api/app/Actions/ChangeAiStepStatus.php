<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Enums\AiStepStatus;
use App\Models\AiStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeAiStepStatus
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * Move a step on (see AiStepStatus for the allowed moves), with what it touched before and after,
     * or the error. Approve, reject, undo and retry are moves too.
     *
     * @param  array{before?: ?object, after?: ?object, error?: ?string}  $data
     *
     * @throws ValidationException when the move isn't allowed
     */
    public function handle(AiStep $step, AiStepStatus $to, array $data = []): AiStep
    {
        return DB::transaction(function () use ($step, $to, $data) {
            $step = AiStep::whereKey($step->id)->lockForUpdate()->firstOrFail();

            if (! $step->status->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "A step that is {$step->status->value} can't become {$to->value}.",
                ]);
            }

            $step->status = $to;
            foreach (['before', 'after', 'error'] as $field) {
                if (array_key_exists($field, $data)) {
                    $step->{$field} = $data[$field];
                }
            }
            // Starting again (retry) clears the last error.
            if ($to === AiStepStatus::Running) {
                $step->error = $data['error'] ?? null;
            }
            $step->save();

            $this->changes->upsert($step);

            return $step;
        });
    }
}
