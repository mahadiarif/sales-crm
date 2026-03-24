<?php

namespace App\Http\Livewire\Dashboard;

use App\Models\Sale;
use App\Models\User;
use App\Models\QuarterlyTarget;
use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class QuarterlySalesReport extends Component
{
    public $year;
    public $userId;
    public $teamLeaderId;
    public $search;
    public $availableYears = [];
    public $users = [];
    public $teamLeaders = [];

    public function mount()
    {
        $this->year = date('Y');
        
        // Fetch years from sales for the filter
        $this->availableYears = Sale::selectRaw('EXTRACT(YEAR FROM created_at) as year')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year')
            ->toArray();

        if (empty($this->availableYears)) {
            $this->availableYears = [date('Y')];
        }

        $this->users = User::orderBy('name')->get();
        
        // Get users who are team leaders
        $this->teamLeaders = User::whereHas('teamMembers')->orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.dashboard.quarterly-sales-report', [
            'individualPerformance' => $this->getIndividualPerformance(),
            'teamPerformance' => $this->getTeamPerformance(),
            'quarterlySummary' => $this->getQuarterlySummary(),
        ])
        ->layout('tyro-dashboard::layouts.admin', ['title' => 'Quarterly Sales Report'])
        ->section('content');
    }

    public function export($format)
    {
        $performance = $this->getIndividualPerformance();
        $filename = 'quarterly_sales_report_' . $this->year . '_' . date('Y-m-d');

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($performance) {
                $file = fopen('php://output', 'w');
                fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM
                fputcsv($file, ['Sales Person', 'Jan', 'Feb', 'Mar', 'Q1 Total', 'Apr', 'May', 'Jun', 'Q2 Total', 'Jul', 'Aug', 'Sep', 'Q3 Total', 'Oct', 'Nov', 'Dec', 'Q4 Total', 'Year Total']);
                foreach ($performance as $perf) {
                    fputcsv($file, [
                        $perf['name'],
                        $perf['months'][1], $perf['months'][2], $perf['months'][3], $perf['q1'],
                        $perf['months'][4], $perf['months'][5], $perf['months'][6], $perf['q2'],
                        $perf['months'][7], $perf['months'][8], $perf['months'][9], $perf['q3'],
                        $perf['months'][10], $perf['months'][11], $perf['months'][12], $perf['q4'],
                        $perf['total']
                    ]);
                }
                fclose($file);
            }, $filename . '.csv');
        }

        if ($format === 'excel') {
            return response()->streamDownload(function () use ($performance) {
                $file = fopen('php://output', 'w');
                // Tab separated for Excel
                fputs($file, "Sales Person\tJan\tFeb\tMar\tQ1 Total\tApr\tMay\tJun\tQ2 Total\tJul\tAug\tSep\tQ3 Total\tOct\tNov\tDec\tQ4 Total\tYear Total\n");
                foreach ($performance as $perf) {
                    $row = [
                        $perf['name'],
                        $perf['months'][1], $perf['months'][2], $perf['months'][3], $perf['q1'],
                        $perf['months'][4], $perf['months'][5], $perf['months'][6], $perf['q2'],
                        $perf['months'][7], $perf['months'][8], $perf['months'][9], $perf['q3'],
                        $perf['months'][10], $perf['months'][11], $perf['months'][12], $perf['q4'],
                        $perf['total']
                    ];
                    fputs($file, implode("\t", $row) . "\n");
                }
                fclose($file);
            }, $filename . '.xls', ['Content-Type' => 'application/vnd.ms-excel']);
        }

        if ($format === 'pdf') {
            // Redirect to a controller method for PDF generation since it requires view loading
            return redirect()->route('tyro-dashboard.reports.export.quarterly', [
                'year' => $this->year,
                'userId' => $this->userId,
                'teamLeaderId' => $this->teamLeaderId,
                'search' => $this->search,
                'format' => 'pdf'
            ]);
        }
    }

    private function getIndividualPerformance()
    {
        $query = User::with(['sales' => function ($q) {
            $q->whereYear('created_at', $this->year);
        }]);

        if ($this->userId) {
            $query->where('id', $this->userId);
        }

        if ($this->teamLeaderId) {
            $query->where('team_leader_id', $this->teamLeaderId);
        }

        if ($this->search) {
            $query->where('name', 'like', '%' . $this->search . '%');
        }

        return $query->get()->map(function ($user) {
            $monthlySales = [];
            for ($i = 1; $i <= 12; $i++) {
                $monthlySales[$i] = $user->sales
                    ->filter(fn($sale) => $sale->created_at->month == $i)
                    ->sum('amount');
            }

            $q1Total = $monthlySales[1] + $monthlySales[2] + $monthlySales[3];
            $q2Total = $monthlySales[4] + $monthlySales[5] + $monthlySales[6];
            $q3Total = $monthlySales[7] + $monthlySales[8] + $monthlySales[9];
            $q4Total = $monthlySales[10] + $monthlySales[11] + $monthlySales[12];
            $yearTotal = array_sum($monthlySales);

            return [
                'name' => $user->name,
                'months' => $monthlySales,
                'q1' => $q1Total,
                'q2' => $q2Total,
                'q3' => $q3Total,
                'q4' => $q4Total,
                'total' => $yearTotal,
            ];
        });
    }

    private function getTeamPerformance()
    {
        $leaders = User::whereHas('teamMembers')->get();

        return $leaders->map(function ($leader) {
            // Get all members IDs
            $memberIds = $leader->teamMembers()->pluck('id')->push($leader->id);

            $sales = Sale::whereIn('user_id', $memberIds)
                ->whereYear('created_at', $this->year)
                ->get();

            $q1 = $sales->filter(fn($s) => in_array($s->created_at->month, [1, 2, 3]))->sum('amount');
            $q2 = $sales->filter(fn($s) => in_array($s->created_at->month, [4, 5, 6]))->sum('amount');
            $q3 = $sales->filter(fn($s) => in_array($s->created_at->month, [7, 8, 9]))->sum('amount');
            $q4 = $sales->filter(fn($s) => in_array($s->created_at->month, [10, 11, 12]))->sum('amount');
            $total = $sales->sum('amount');

            return [
                'leader' => $leader->name,
                'q1' => $q1,
                'q2' => $q2,
                'q3' => $q3,
                'q4' => $q4,
                'total' => $total,
            ];
        });
    }

    private function getQuarterlySummary()
    {
        $summary = [];
        for ($q = 1; $q <= 4; $q++) {
            $months = match($q) {
                1 => [1, 2, 3],
                2 => [4, 5, 6],
                3 => [7, 8, 9],
                4 => [10, 11, 12],
            };

            $sales = Sale::whereYear('created_at', $this->year)
                ->whereRaw('EXTRACT(MONTH FROM created_at) IN (' . implode(',', $months) . ')');
            
            if ($this->userId) {
                $sales->where('user_id', $this->userId);
            }
            
            if ($this->teamLeaderId) {
                $memberIds = User::where('team_leader_id', $this->teamLeaderId)->pluck('id')->push($this->teamLeaderId);
                $sales->whereIn('user_id', $memberIds);
            }

            $achieved = $sales->sum('amount');

            // Targets
            $targetQuery = QuarterlyTarget::where('year', $this->year)->where('quarter', $q);
            if ($this->userId) {
                $targetQuery->where('user_id', $this->userId);
            } elseif ($this->teamLeaderId) {
                $targetQuery->where('user_id', $this->teamLeaderId);
            } else {
                // Global target or sum of all user targets
                $targetQuery->whereNull('user_id'); // Assuming global targets are user_id null
            }

            $target = $targetQuery->sum('target_amount') ?: 0;
            $diff = $achieved - $target;

            $summary[$q] = [
                'achieved' => $achieved,
                'target' => $target,
                'diff' => $diff,
                'percent' => $target > 0 ? round(($achieved / $target) * 100, 1) : 0,
            ];
        }

        return $summary;
    }
}
