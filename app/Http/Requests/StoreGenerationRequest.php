<?php

namespace App\Http\Requests;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $refs = $this->input('reference_images', []);

        if (is_array($refs)) {
            $cleaned = array_values(array_filter(
                array_map(static fn ($url) => is_string($url) ? trim($url) : $url, $refs),
                static fn ($url) => $url !== '' && $url !== null,
            ));

            $this->merge(['reference_images' => $cleaned]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $text = (string) $value;

                    if (mb_strlen(trim($text), 'UTF-8') === 0) {
                        $fail('Please enter a prompt describing the image you want to generate.');

                        return;
                    }

                    if (mb_strlen($text, 'UTF-8') > 4000) {
                        $fail('The prompt may not be greater than 4,000 characters.');
                    }
                },
            ],
            'size' => ['required', 'string', Rule::enum(ImageSize::class)],
            'quality' => ['required', 'string', Rule::enum(ImageQuality::class)],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You may include at most 4 reference image URLs.',
            'reference_images.*.url' => 'Each reference image must be a valid http:// or https:// URL.',
        ];
    }
}