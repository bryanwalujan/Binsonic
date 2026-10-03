<?php

namespace App\Http\Requests;

class BinsonicReadingRequest extends TelemetryRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['audio_label', 'audio_confidence', 'vision_label', 'vision_confidence', 'final_label', 'destination'] as $key) {
            unset($rules[$key]);
        }

        return $rules;
    }
}
