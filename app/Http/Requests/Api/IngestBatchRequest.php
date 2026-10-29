<?php

namespace App\Http\Requests\Api;

use App\Watch\EventStore;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IngestBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('watch_project');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'batch_id' => ['nullable', 'string', 'max:64'],
            'sdk_version' => ['nullable', 'string', 'max:32'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'timestamp' => ['nullable', 'date'],

            'events' => ['required', 'array', 'min:1', 'max:1000'],
            'events.*.type' => ['required', 'string', Rule::in(EventStore::TYPES)],
            'events.*.id' => ['nullable', 'string', 'max:64'],
            'events.*.data' => ['required', 'array'],
        ];
    }
}
