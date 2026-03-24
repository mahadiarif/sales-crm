<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Sale;
use App\Models\Visit;
use App\Models\User;
use App\Models\Service;
use App\Models\FollowUp;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\QuarterlyTarget;
use App\Domains\Marketing\Models\Campaign;
use Illuminate\Database\Eloquent\Builder;

class ReportController extends Controller
{
    /**
     * Sales Report with filtering, aggregates, and multi-format export.
     */
    public function sales(Request $request)
    {
        $query = Sale::with(['lead', 'user', 'service']);

        // ── Quick date presets ────────────────────────────────────────────────
        if ($request->filled('quick')) {
            match ($request->quick) {
                    'today' => $query->whereDate('closed_at', Carbon::today()),
                    'this_week' => $query->whereBetween('closed_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
                    'this_month' => $query->whereMonth('closed_at', Carbon::now()->month)
                    ->whereYear('closed_at', Carbon::now()->year),
                    default => null,
                };
        }
        else {
            // Manual date range (only if no quick preset)
            if ($request->filled('start_date')) {
                $query->whereDate('closed_at', '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->whereDate('closed_at', '<=', $request->end_date);
            }
        }

        // ── Other filters ─────────────────────────────────────────────────────
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('service_id')) {
            $query->where('service_id', $request->service_id);
        }
        // Lead status filter (joins through lead relationship)
        if ($request->filled('lead_status')) {
            $query->whereHas('lead', fn($q) => $q->where('status', $request->lead_status));
        }

        // Zone/Territory filter (joins through lead relationship)
        if ($request->filled('zone')) {
            $query->whereHas('lead', fn($q) => $q->where('zone', $request->zone));
        }

        // ── Text Search ──────────────────────────────────────────────────────
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function (Builder $q) use ($search) {
                $q->where('remarks', 'like', "%{$search}%")
                  ->orWhereHas('lead', function (Builder $ql) use ($search) {
                      $ql->where('company_name', 'like', "%{$search}%")
                         ->orWhere('client_name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('user', function (Builder $qu) use ($search) {
                      $qu->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Export before paginating
        if ($request->has('export')) {
            $data = (clone $query)->latest('closed_at')->get();
            return $this->exportSales($data, $request->export);
        }

        // Aggregates on full filtered result (not just this page)
        $aggregates = (clone $query)
            ->selectRaw('COUNT(*) as total_deals, COALESCE(SUM(amount), 0) as total_revenue')
            ->first();

        $sales = $query->latest('closed_at')->paginate(20)->withQueryString();
        $users = $this->getAccessibleUsers();
        $services = Service::all();
        $zones = Lead::whereNotNull('zone')->distinct()->pluck('zone');

        return view('vendor.tyro-dashboard.reports.sales', compact('sales', 'users', 'services', 'aggregates', 'zones'));
    }

    /**
     * Visit Report with filtering and export.
     */
    public function visits(Request $request)
    {
        $query = Visit::with(['lead', 'user', 'service']);

        // ── Quick date presets ────────────────────────────────────────────────
        if ($request->filled('quick')) {
            match ($request->quick) {
                    'today' => $query->whereDate('visit_date', Carbon::today()),
                    'this_week' => $query->whereBetween('visit_date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
                    'this_month' => $query->whereMonth('visit_date', Carbon::now()->month)
                    ->whereYear('visit_date', Carbon::now()->year),
                    default => null,
                };
        }
        else {
            // Manual date range (only if no quick preset)
            if ($request->filled('start_date')) {
                $query->whereDate('visit_date', '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->whereDate('visit_date', '<=', $request->end_date);
            }
        }

        // ── Other filters ─────────────────────────────────────────────────────
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->lead_id);
        }
        if ($request->filled('service_id')) {
            $query->where('service_id', $request->service_id);
        }
        // Lead status filter (joins through lead relationship)
        if ($request->filled('lead_status')) {
            $query->whereHas('lead', fn($q) => $q->where('status', $request->lead_status));
        }
        // Zone/Territory filter (joins through lead relationship)
        if ($request->filled('zone')) {
            $query->whereHas('lead', fn($q) => $q->where('zone', $request->zone));
        }

        // ── Text Search ──────────────────────────────────────────────────────
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function (Builder $q) use ($search) {
                $q->where('meeting_notes', 'like', "%{$search}%")
                  ->orWhereHas('lead', function (Builder $ql) use ($search) {
                      $ql->where('company_name', 'like', "%{$search}%")
                         ->orWhere('client_name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('user', function (Builder $qu) use ($search) {
                      $qu->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('export')) {
            return $this->exportVisits((clone $query)->latest('visit_date')->get(), $request->export);
        }

        $visits = $query->latest('visit_date')->paginate(20)->withQueryString();
        $users = $this->getAccessibleUsers();
        $leads = Lead::select('id', 'company_name')->get();
        $services = Service::all();
        $zones = Lead::whereNotNull('zone')->distinct()->pluck('zone');

        return view('vendor.tyro-dashboard.reports.visits', compact('visits', 'users', 'leads', 'services', 'zones'));
    }

    /**
     * Lead Report with filtering and export.
     */
    public function leads(Request $request)
    {
        $query = Lead::with(['assignedUser', 'service']);

        // ── Quick date presets ────────────────────────────────────────────────
        if ($request->filled('quick')) {
            match ($request->quick) {
                    'today' => $query->whereDate('created_at', Carbon::today()),
                    'this_week' => $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
                    'this_month' => $query->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year),
                    default => null,
                };
        }
        else {
            if ($request->filled('start_date')) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('user_id')) {
            $query->where('assigned_user', $request->user_id);
        }
        if ($request->filled('service_id')) {
            $query->where('service_id', $request->service_id);
        }
        if ($request->filled('zone')) {
            $query->where('zone', $request->zone);
        }

        // ── Text Search ──────────────────────────────────────────────────────
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function (Builder $q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                  ->orWhere('client_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhereHas('assignedUser', function (Builder $qu) use ($search) {
                      $qu->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('export')) {
            return $this->exportLeads((clone $query)->latest()->get(), $request->export);
        }

        $leads = $query->latest()->paginate(20)->withQueryString();
        $users = $this->getAccessibleUsers();
        $services = Service::all();
        $zones = Lead::whereNotNull('zone')->distinct()->pluck('zone');

        return view('vendor.tyro-dashboard.reports.leads', compact('leads', 'users', 'services', 'zones'));
    }

    public function userPerformance(Request $request, \App\Models\User $user)
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $stats = [
            'total_leads' => Lead::where('assigned_user', $user->id)->count(),
            'total_visits' => Visit::where('user_id', $user->id)->count(),
            'total_followups' => FollowUp::where('user_id', $user->id)->count(),
            'total_sales' => Sale::where('user_id', $user->id)->count(),
            'monthly_revenue' => Sale::where('user_id', $user->id)
            ->whereBetween('closed_at', [$startOfMonth, $endOfMonth])
            ->sum('amount'),
        ];

        // Calculate conversion rate
        $stats['conversion_rate'] = $stats['total_leads'] > 0
            ? round(($stats['total_sales'] / $stats['total_leads']) * 100, 1)
            : 0;

        // Recent leads
        $recentLeads = Lead::with('service')
            ->where('assigned_user', $user->id)
            ->latest()
            ->limit(10)
            ->get();

        // Monthly sales trend (last 6 months)
        $salesTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $amount = Sale::where('user_id', $user->id)
                ->whereMonth('closed_at', $month->month)
                ->whereYear('closed_at', $month->year)
                ->sum('amount');

            $salesTrend[] = [
                'month' => $month->format('M'),
                'amount' => $amount
            ];
        }

        return view('vendor.tyro-dashboard.reports.user-performance', compact('user', 'stats', 'recentLeads', 'salesTrend'));
    }

    // ─── Exporters ────────────────────────────────────────────────────────────

    private function exportSales($data, string $format)
    {
        $filename = 'sales_report_' . date('Y-m-d');

        if ($format === 'csv') {
            return $this->streamCsv($filename . '.csv', function ($file) use ($data) {
                fputcsv($file, ['#', 'Date', 'Lead / Company', 'Contact', 'Executive', 'Product', 'Amount (BDT)', 'Lead Status', 'Remarks']);
                foreach ($data as $i => $sale) {
                    fputcsv($file, [
                        $i + 1,
                        optional($sale->closed_at)->format('Y-m-d'),
                        $sale->lead->company_name ?? 'N/A',
                        $sale->lead->client_name ?? '',
                        $sale->user->name ?? 'N/A',
                        $sale->service->name ?? 'N/A',
                        $sale->amount,
                        $sale->lead->status ?? 'N/A',
                        $sale->remarks,
                    ]);
                }
                // Totals row
                fputcsv($file, []);
                fputcsv($file, ['', '', '', '', '', 'TOTAL', $data->sum('amount'), '', '']);
                fputcsv($file, ['', '', '', '', '', 'DEALS', $data->count(), '', '']);
            });
        }

        if ($format === 'excel') {
            return $this->streamExcel($filename . '.xls', function ($file) use ($data) {
                fputcsv($file, ['#', 'Date', 'Lead', 'Contact', 'Executive', 'Product', 'Amount', 'Status', 'Remarks'], "\t");
                foreach ($data as $i => $sale) {
                    fputcsv($file, [
                        $i + 1,
                        optional($sale->closed_at)->format('Y-m-d'),
                        $sale->lead->company_name ?? 'N/A',
                        $sale->lead->client_name ?? '',
                        $sale->user->name ?? 'N/A',
                        $sale->service->name ?? 'N/A',
                        $sale->amount,
                        $sale->lead->status ?? '',
                        $sale->remarks,
                    ], "\t");
                }
            }, 'application/vnd.ms-excel');
        }

        if ($format === 'pdf') {
            return $this->streamPdf('vendor.tyro-dashboard.reports.pdf.sales', [
                'data' => $data,
                'title' => 'Sales Report',
                'generated_at' => now()->format('d M Y, h:i A'),
                'total_revenue' => $data->sum('amount'),
                'total_deals' => $data->count(),
            ]);
        }

        return back()->with('error', 'Unknown export format.');
    }

    private function exportVisits($data, string $format)
    {
        $filename = 'visit_report_' . date('Y-m-d');

        if ($format === 'csv') {
            return $this->streamCsv($filename . '.csv', function ($file) use ($data) {
                fputcsv($file, ['#', 'Date', 'Lead', 'Contact', 'Executive', 'Visit #', 'Product Offered', 'Notes', 'Location']);
                foreach ($data as $i => $visit) {
                    fputcsv($file, [
                        $i + 1,
                        optional($visit->visit_date)->format('Y-m-d'),
                        $visit->lead->company_name ?? 'N/A',
                        $visit->lead->client_name ?? '',
                        $visit->user->name ?? 'N/A',
                        $visit->visit_number,
                        $visit->service->name ?? '',
                        $visit->meeting_notes,
                        $visit->location,
                    ]);
                }
            });
        }

        if ($format === 'excel') {
            return $this->streamExcel($filename . '.xls', function ($file) use ($data) {
                fputcsv($file, ['#', 'Date', 'Lead', 'Contact', 'Executive', 'Visit #', 'Product Offered', 'Notes', 'Location'], "\t");
                foreach ($data as $i => $visit) {
                    fputcsv($file, [
                        $i + 1,
                        optional($visit->visit_date)->format('Y-m-d'),
                        $visit->lead->company_name ?? 'N/A',
                        $visit->lead->client_name ?? '',
                        $visit->user->name ?? 'N/A',
                        $visit->visit_number,
                        $visit->service->name ?? '',
                        $visit->meeting_notes,
                        $visit->location,
                    ], "\t");
                }
            }, 'application/vnd.ms-excel');
        }

        if ($format === 'pdf') {
            return $this->streamPdf('vendor.tyro-dashboard.reports.pdf.visits', [
                'data' => $data,
                'title' => 'Visit Report',
                'generated_at' => now()->format('d M Y, h:i A'),
            ]);
        }

        return back()->with('error', 'Unknown export format.');
    }

    private function exportLeads($data, string $format)
    {
        $filename = 'lead_report_' . date('Y-m-d');

        if ($format === 'csv') {
            return $this->streamCsv($filename . '.csv', function ($file) use ($data) {
                fputcsv($file, ['#', 'Created', 'Company', 'Contact', 'Phone', 'Email', 'Service', 'Status', 'Assigned To']);
                foreach ($data as $i => $lead) {
                    fputcsv($file, [
                        $i + 1,
                        $lead->created_at->format('Y-m-d'),
                        $lead->company_name,
                        $lead->client_name,
                        $lead->phone,
                        $lead->email,
                        $lead->service->name ?? '',
                        $lead->status,
                        $lead->assignedUser->name ?? 'Unassigned',
                    ]);
                }
            });
        }

        if ($format === 'excel') {
            return $this->streamExcel($filename . '.xls', function ($file) use ($data) {
                fputcsv($file, ['#', 'Created', 'Company', 'Contact', 'Phone', 'Email', 'Service', 'Status', 'Assigned To'], "\t");
                foreach ($data as $i => $lead) {
                    fputcsv($file, [
                        $i + 1,
                        $lead->created_at->format('Y-m-d'),
                        $lead->company_name,
                        $lead->client_name,
                        $lead->phone,
                        $lead->email,
                        $lead->service->name ?? '',
                        $lead->status,
                        $lead->assignedUser->name ?? 'Unassigned',
                    ], "\t");
                }
            }, 'application/vnd.ms-excel');
        }

        if ($format === 'pdf') {
            return $this->streamPdf('vendor.tyro-dashboard.reports.pdf.leads', [
                'data' => $data,
                'title' => 'Lead Report',
                'generated_at' => now()->format('d M Y, h:i A'),
            ]);
        }

        return back()->with('error', 'Unknown export format.');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function streamCsv(string $filename, callable $writer)
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($writer) {
            $file = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            $writer($file);
            fclose($file);
        }, 200, $headers);
    }

    private function streamExcel(string $filename, callable $writer, string $contentType = 'application/vnd.ms-excel')
    {
        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($writer) {
            $file = fopen('php://output', 'w');
            $writer($file);
            fclose($file);
        }, 200, $headers);
    }

    private function streamPdf(string $view, array $data)
    {
        // Requires: composer require barryvdh/laravel-dompdf
        // If not installed, we fall back to a printable HTML page instead.
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $data);
            return $pdf->download($data['title'] . '_' . date('Y-m-d') . '.pdf');
        }

        // Fallback: return a print-ready HTML page
        return response()->view($view, array_merge($data, ['printMode' => true]));
    }

    public function teamPerformance(Request $request)
    {
        $user = auth()->user();
        $currentStr = strtolower($user->role ?? '');
        $tyroRoles = method_exists($user, 'tyroRoleSlugs') ? $user->tyroRoleSlugs() : [];
        
        $isSuperAdmin = in_array($currentStr, ['super admin', 'super_admin', 'super-admin', 'admin']) || in_array('super_admin', $tyroRoles) || in_array('super-admin', $tyroRoles) || in_array('admin', $tyroRoles);
        $isManager    = in_array($currentStr, ['manager']) || in_array('manager', $tyroRoles);
        $isTeamLeader = in_array($currentStr, ['team leader', 'team_leader']) || in_array('team_leader', $tyroRoles);
        
        $query = User::query();
        
        if ($isTeamLeader && !$isSuperAdmin && !$isManager) {
            $query->where('team_leader_id', $user->id);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }
        
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $teamMembers = $query->get()->map(function($member) use ($startOfMonth, $endOfMonth) {
            $member->stats = [
                'total_leads' => \App\Models\Lead::where('assigned_user', $member->id)->count(),
                'total_visits' => \App\Models\Visit::where('user_id', $member->id)->count(),
                'total_followups' => \App\Models\FollowUp::where('user_id', $member->id)->whereNotNull('completed_at')->count(),
                'total_sales' => \App\Models\Sale::where('user_id', $member->id)->count(),
                'monthly_revenue' => \App\Models\Sale::where('user_id', $member->id)
                    ->whereBetween('closed_at', [$startOfMonth, $endOfMonth])
                    ->sum('amount'),
            ];
            return $member;
        });

        $aggregates = (object)[
            'total_team_members' => $teamMembers->count(),
            'total_leads' => $teamMembers->sum(function($member) { return $member->stats['total_leads']; }),
            'total_sales' => $teamMembers->sum(function($member) { return $member->stats['total_sales']; }),
            'monthly_revenue' => $teamMembers->sum(function($member) { return $member->stats['monthly_revenue']; }),
        ];

        // ── Export Logic ─────────────────────────────────────────────────────
        if ($request->has('export')) {
            $filename = 'team_performance_report_' . date('Y-m-d');
            
            if ($request->export === 'csv') {
                return response()->streamDownload(function () use ($teamMembers) {
                    $file = fopen('php://output', 'w');
                    fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM
                    fputcsv($file, ['Executive', 'Role', 'Total Leads', 'Visits', 'Follow-ups', 'Total Sales', 'MTD Revenue']);
                    foreach ($teamMembers as $m) {
                        fputcsv($file, [$m->name, $m->role, $m->stats['total_leads'], $m->stats['total_visits'], $m->stats['total_followups'], $m->stats['total_sales'], $m->stats['monthly_revenue']]);
                    }
                    fclose($file);
                }, $filename . '.csv');
            }

            if ($request->export === 'excel') {
                return response()->streamDownload(function () use ($teamMembers) {
                    $file = fopen('php://output', 'w');
                    fputs($file, "Executive\tRole\tTotal Leads\tVisits\tFollow-ups\tTotal Sales\tMTD Revenue\n");
                    foreach ($teamMembers as $m) {
                        $row = [$m->name, $m->role, $m->stats['total_leads'], $m->stats['total_visits'], $m->stats['total_followups'], $m->stats['total_sales'], $m->stats['monthly_revenue']];
                        fputs($file, implode("\t", $row) . "\n");
                    }
                    fclose($file);
                }, $filename . '.xls', ['Content-Type' => 'application/vnd.ms-excel']);
            }

            if ($request->export === 'pdf') {
                return $this->streamPdf('vendor.tyro-dashboard.reports.pdf.team_performance', [
                    'teamMembers' => $teamMembers,
                    'aggregates' => $aggregates,
                    'title' => 'Team Performance Report',
                    'generated_at' => now()->format('d M Y, h:i A'),
                ]);
            }
        }

        return view('vendor.tyro-dashboard.reports.team-performance', compact('teamMembers', 'aggregates'));
    }

    public function quarterly()
    {
        return view('vendor.tyro-dashboard.reports.quarterly');
    }

    private function getAccessibleUsers()
    {
        $user = auth()->user();
        if (!$user) return collect();

        $currentStr = strtolower($user->role ?? '');
        $tyroRoles = method_exists($user, 'tyroRoleSlugs') ? $user->tyroRoleSlugs() : [];
        
        $isSuperAdmin = in_array($currentStr, ['super admin', 'super_admin', 'super-admin', 'admin']) || in_array('super_admin', $tyroRoles) || in_array('super-admin', $tyroRoles) || in_array('admin', $tyroRoles);
        $isManager    = in_array($currentStr, ['manager']) || in_array('manager', $tyroRoles);
        $isTeamLeader = in_array($currentStr, ['team leader', 'team_leader', 'team-leader']) || in_array('team_leader', $tyroRoles) || in_array('team-leader', $tyroRoles);

        if ($isSuperAdmin || $isManager) {
            return User::all();
        } elseif ($isTeamLeader) {
            $teamMemberIds = $user->teamMembers()->pluck('id')->push($user->id)->toArray();
            return User::whereIn('id', $teamMemberIds)->get();
        } else {
            return User::where('id', $user->id)->get();
        }
    }

    public function exportQuarterly(Request $request)
    {
        $year = $request->year ?? date('Y');
        $userId = $request->userId;
        $teamLeaderId = $request->teamLeaderId;
        $search = $request->search;
        $format = $request->format ?? 'pdf';

        // Summary Data
        $summary = [];
        for ($q = 1; $q <= 4; $q++) {
            $months = match($q) {
                1 => [1, 2, 3],
                2 => [4, 5, 6],
                3 => [7, 8, 9],
                4 => [10, 11, 12],
            };

            $salesQuery = Sale::whereYear('created_at', $year)
                ->whereIn(\DB::raw('EXTRACT(MONTH FROM created_at)'), $months);
            
            if ($userId) {
                $salesQuery->where('user_id', $userId);
            }
            if ($teamLeaderId) {
                $memberIds = User::where('team_leader_id', $teamLeaderId)->pluck('id')->push($teamLeaderId);
                $salesQuery->whereIn('user_id', $memberIds);
            }

            $achieved = $salesQuery->sum('amount');

            $targetQuery = QuarterlyTarget::where('year', $year)->where('quarter', $q);
            if ($userId) {
                $targetQuery->where('user_id', $userId);
            } elseif ($teamLeaderId) {
                $targetQuery->where('user_id', $teamLeaderId);
            } else {
                $targetQuery->whereNull('user_id');
            }

            $target = $targetQuery->sum('target_amount') ?: 0;

            $summary[$q] = [
                'achieved' => $achieved,
                'target' => $target,
                'percent' => $target > 0 ? round(($achieved / $target) * 100, 1) : 0,
            ];
        }

        // Individual Performance
        $perfQuery = User::with(['sales' => function ($q) use ($year) {
            $q->whereYear('created_at', $year);
        }]);

        if ($userId) $perfQuery->where('id', $userId);
        if ($teamLeaderId) $perfQuery->where('team_leader_id', $teamLeaderId);
        if ($search) $perfQuery->where('name', 'like', '%' . $search . '%');

        $performance = $perfQuery->get()->map(function ($user) {
            $monthlySales = [];
            for ($i = 1; $i <= 12; $i++) {
                $monthlySales[$i] = $user->sales->filter(fn($sale) => $sale->created_at->month == $i)->sum('amount');
            }
            return [
                'name' => $user->name,
                'months' => $monthlySales,
                'q1' => array_sum(array_slice($monthlySales, 0, 3, true)),
                'q2' => array_sum(array_slice($monthlySales, 3, 3, true)),
                'q3' => array_sum(array_slice($monthlySales, 6, 3, true)),
                'q4' => array_sum(array_slice($monthlySales, 9, 3, true)),
                'total' => array_sum($monthlySales),
            ];
        });

        if ($format === 'pdf') {
            return $this->streamPdf('vendor.tyro-dashboard.reports.pdf.quarterly', [
                'summary' => $summary,
                'performance' => $performance,
                'year' => $year,
                'title' => "Quarterly Sales Report — $year",
                'generated_at' => now()->format('d M Y, h:i A'),
            ]);
        }

        return back()->with('error', 'Unsupported export format.');
    }

    public function exportCampaigns(Request $request)
    {
        $search = $request->search;
        $format = $request->format ?? 'pdf';

        $query = Campaign::with('creator')->withCount('recipients');

        if ($search) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        $campaigns = $query->latest()->get();

        if ($format === 'pdf') {
            return $this->streamPdf('vendor.tyro-dashboard.reports.pdf.campaigns', [
                'campaigns' => $campaigns,
                'title' => 'Marketing Campaigns Report',
                'generated_at' => now()->format('d M Y, h:i A'),
            ]);
        }

        return back()->with('error', 'Unsupported export format.');
    }
}
