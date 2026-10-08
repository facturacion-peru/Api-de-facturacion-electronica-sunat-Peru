<?php

namespace App\Http\Resources;

use App\Models\ImportPreview;
use Illuminate\Http\Request;

/** @mixin ImportPreview */
class ImportPreviewResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'mode' => $this->mode,
            'expires_at' => $this->expires_at->toIso8601String(),
            'can_confirm' => $this->canConfirm(),
            'summary' => $this->summary,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'changes' => $this->changes,
        ];
    }
}
