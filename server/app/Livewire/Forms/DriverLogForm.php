<?php

namespace App\Livewire\Forms;

use App\Enums\DriverLogCategory;
use App\Models\DriverLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Form;

class DriverLogForm extends Form
{
    public ?DriverLog $editing = null;

    public ?int $driver_id = null;

    public string $occurred_on = '';

    public string $category = 'neutral';

    public string $body = '';

    public function rules(): array
    {
        return [
            'occurred_on' => ['required', 'date'],
            'category' => ['required', Rule::enum(DriverLogCategory::class)],
            'body' => ['required', 'string', 'max:4000'],
        ];
    }

    public function setLog(DriverLog $log): void
    {
        $this->editing = $log;
        $this->driver_id = $log->driver_id;
        $this->occurred_on = $log->occurred_on->toDateString();
        $this->category = $log->category->value;
        $this->body = $log->body;
    }

    public function save(): DriverLog
    {
        $validated = $this->validate();

        if ($this->editing) {
            $this->editing->update([...$validated, 'updated_by' => Auth::id()]);
            $log = $this->editing;
        } else {
            $log = DriverLog::create([
                ...$validated,
                'driver_id' => $this->driver_id,
                'created_by' => Auth::id(),
            ]);
        }

        $this->reset();

        return $log;
    }
}
