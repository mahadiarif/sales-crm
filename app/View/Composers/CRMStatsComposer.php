<?php

namespace App\View\Composers;

use App\Models\Lead;
use App\Models\Visit;
use App\Models\FollowUp;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Carbon\Carbon;

class CRMStatsComposer
{
    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();

        $crmStats = [
            'total_leads' => Lead::count(),
            'today_visits' => Visit::whereDate('visit_date', $today)->count(),
            'today_followups' => FollowUp::whereDate('scheduled_at', $today)
            ->whereNull('completed_at')
            ->count(),
            'monthly_sales' => Sale::whereMonth('closed_at', Carbon::now()->month)
            ->whereYear('closed_at', Carbon::now()->year)
            ->sum('amount'),
        ];

        // Weekly Leads (Last 7 days)
        $weekly_bars = [];
        $max_leads = 0;
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $count = Lead::whereDate('created_at', $date)->count();
            $max_leads = max($max_leads, $count);
            $weekly_bars[] = [
                'label' => $date->format('D'),
                'value' => $count,
                'date' => $date->format('Y-m-d')
            ];
        }

        foreach ($weekly_bars as &$bar) {
            $bar['pct'] = $max_leads > 0 ? ($bar['value'] / $max_leads) * 100 : 0;
        }

        // Leads by Status
        $statuses = [
            'new' => ['label' => 'New', 'color' => '#3b82f6'], // blue
            'contacted' => ['label' => 'Contacted', 'color' => '#06b6d4'], // cyan
            'interested' => ['label' => 'Interested', 'color' => '#f59e0b'], // amber
            'closed' => ['label' => 'Closed', 'color' => '#10b981'], // emerald
            'lost' => ['label' => 'Lost', 'color' => '#ef4444'], // red
        ];

        $status_donut = [];
        foreach ($statuses as $key => $status) {
            $count = Lead::where('status', $key)->count();
            $status_donut[] = [
                'label' => $status['label'],
                'count' => $count,
                'pct' => ($crmStats['total_leads'] > 0) ? round(($count / $crmStats['total_leads']) * 100) : 0,
                'color' => $status['color']
            ];
        }

        // Monthly Revenue Chart (Current Month)
        $daysInMonth = Carbon::now()->daysInMonth;
        $revenue_data = [];
        $max_revenue = 0;
        for ($i = 1; $i <= $daysInMonth; $i++) {
            $date = Carbon::now()->startOfMonth()->addDays($i - 1);
            $amount = Sale::whereDate('closed_at', $date)->sum('amount');
            $max_revenue = max($max_revenue, $amount);
            $revenue_data[] = [
                'x' => ($i - 1) * (600 / max(1, $daysInMonth - 1)),
                'y' => $amount,
            ];
        }

        // Scaling y values for SVG (viewBox 0 0 600 180, bottom line at 150)
        $line_points = "";
        $area_points = "0,150 ";
        foreach ($revenue_data as $point) {
            $y_svg = 150 - ($max_revenue > 0 ? ($point['y'] / $max_revenue) * 120 : 0);
            $line_points .= $point['x'] . "," . $y_svg . " ";
            $area_points .= $point['x'] . "," . $y_svg . " ";
        }
        $area_points .= "600,150";

        // Weekly Sales (Last 7 days)
        $weekly_sales_bars = [];
        $max_sales = 0;
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $amount = Sale::whereDate('closed_at', $date)->sum('amount');
            $max_sales = max($max_sales, $amount);
            $weekly_sales_bars[] = [
                'label' => $date->format('D'),
                'value' => $amount,
                'date' => $date->format('Y-m-d')
            ];
        }

        foreach ($weekly_sales_bars as &$bar) {
            $bar['pct'] = $max_sales > 0 ? ($bar['value'] / $max_sales) * 100 : 0;
        }

        // Product Wise Sales (Optimized for Top 5, Current Month)
        $product_sales = Sale::query()
            ->join('services', 'sales.service_id', '=', 'services.id')
            ->join('leads', 'sales.lead_id', '=', 'leads.id')
            ->leftJoin('service_packages as sp_sale', 'sales.service_package_id', '=', 'sp_sale.id')
            ->leftJoin('service_packages as sp_lead', 'leads.service_package_id', '=', 'sp_lead.id')
            ->select(
                'services.name as product',
                DB::raw("COALESCE(sp_sale.name, sp_lead.name, '-') as package"),
                DB::raw('SUM(sales.amount) as total_sales')
            )
            ->whereMonth('sales.closed_at', Carbon::now()->month)
            ->whereYear('sales.closed_at', Carbon::now()->year)
            ->groupBy('sales.service_id', 'services.name', 'package')
            ->orderByDesc('total_sales')
            ->limit(5)
            ->get();

        // Executive Performance (Current Month)
        $executive_performance = Sale::with('user')
            ->whereMonth('closed_at', Carbon::now()->month)
            ->whereYear('closed_at', Carbon::now()->year)
            ->selectRaw('user_id, sum(amount) as total_amount, count(*) as total_deals')
            ->groupBy('user_id')
            ->orderBy('total_amount', 'desc')
            ->get();

        // Today's Visits List
        $today_visits_list = Visit::with(['lead', 'user'])
            ->whereDate('visit_date', $today)
            ->latest()
            ->limit(5)
            ->get();

        // Upcoming Followups List
        $upcoming_followups_list = FollowUp::with(['lead', 'user'])
            ->whereDate('scheduled_at', '>=', $today)
            ->whereNull('completed_at')
            ->orderBy('scheduled_at', 'asc')
            ->limit(5)
            ->get();

        // Latest Leads
        $latest_leads = Lead::with('service')
            ->latest()
            ->limit(5)
            ->get();

        // Latest Sales
        $latest_sales = Sale::with(['lead.servicePackage', 'user', 'service', 'servicePackage'])
            ->latest('closed_at')
            ->limit(5)
            ->get();

        $crmCharts = [
            'weekly_bars' => $weekly_bars,
            'weekly_sales_bars' => $weekly_sales_bars,
            'status_donut' => $status_donut,
            'status_total' => $crmStats['total_leads'],
            'revenue_line_path' => "M " . trim($line_points),
            'revenue_area_path' => "M " . trim($area_points) . " Z",
            'revenue_max' => $max_revenue,
            'revenue_total_bdt' => $crmStats['monthly_sales'],
            'revenue_range_left' => Carbon::now()->startOfMonth()->format('M 1'),
            'revenue_range_right' => Carbon::now()->endOfMonth()->format('M j'),
            'product_sales' => $product_sales,
            'executive_performance' => $executive_performance,
            'today_visits_list' => $today_visits_list,
            'upcoming_followups_list' => $upcoming_followups_list,
            'latest_leads' => $latest_leads,
            'latest_sales' => $latest_sales,
            'recent_activities' => $this->getFormattedActivities(),
        ];

        $view->with('crmStats', $crmStats)->with('crmCharts', $crmCharts);
    }

    private function getFormattedActivities(): array
    {
        $activities = [];

        // Fetch Lead Activities
        $leadActivities = \App\Models\LeadActivity::with(['lead', 'user'])->latest()->limit(5)->get();
        foreach ($leadActivities as $activity) {
            $activities[] = [
                'title' => $this->getActivityTitle($activity->type),
                'subtitle' => $activity->description,
                'actor' => $activity->user->name ?? 'System',
                'actor_meta' => $activity->user->email ?? 'Automation',
                'status' => 'Completed',
                'status_badge_class' => 'badge-success',
                'when' => $activity->created_at->diffForHumans(),
                'created_at' => $activity->created_at,
            ];
        }

        return collect($activities)->sortByDesc('created_at')->values()->all();
    }

    private function getActivityTitle(string $type): string
    {
        return str_replace('_', ' ', ucwords($type));
    }
}
