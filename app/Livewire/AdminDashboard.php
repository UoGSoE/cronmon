<?php

namespace App\Livewire;

use App\Services\EstateStats;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class AdminDashboard extends Component
{
    public function render()
    {
        $stats = new EstateStats;

        return view('livewire.admin-dashboard', [
            'totalCount' => $stats->totalCount(),
            'alertingCount' => $stats->alertingCount(),
            'silencedCount' => $stats->silencedCount(),
            'neverCheckedInCount' => $stats->neverCheckedInCount(),
            'alertingJobs' => $stats->alertingJobs(),
            'breakdownRows' => $stats->breakdownRows(),
        ]);
    }
}
