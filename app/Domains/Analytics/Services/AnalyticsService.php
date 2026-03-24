<?php

namespace App\Domains\Analytics\Services;

use App\Models\Lead;
use App\Models\Sale;
use App\Models\Visit;
use App\Models\FollowUp;
use App\Models\Proposal;
use App\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsService
{
    /**
     * Get Sales Funnel data (Leads count per stage)
     */
    public function getSalesFunnel(): array
    {
        return PipelineStage::withCount('leads')
            ->orderBy('order_column')
            ->get()
            ->map(fn($stage) => [
                'stage' => $stage->name,
                'count' => $stage->leads_count,
                'color' => $stage->color
            ])->toArray();
    }

    /**
     * Calculate Conversion Rates (Leads to Sales)
     */
    public function getConversionStats(): array
    {
        $totalLeads = Lead::accessible()->count();
        $totalSales = Sale::accessible()->count();
        $totalProposals = Proposal::accessible()->count();
        
        $conversionRate = $totalLeads > 0 ? ($totalSales / $totalLeads) * 100 : 0;
        $proposalToSaleRate = $totalProposals > 0 ? ($totalSales / $totalProposals) * 100 : 0;

        return [
            'total_leads' => $totalLeads,
            'total_proposals' => $totalProposals,
            'total_sales' => $totalSales,
            'conversion_rate' => round($conversionRate, 2),
            'proposal_to_sale_rate' => round($proposalToSaleRate, 2),
        ];
    }

    /**
     * Get Proposal Pipeline Statuses
     */
    public function getProposalStats(): array
    {
        return Proposal::accessible()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()->toArray();
    }

    /**
     * Executive Performance (Sales Volume & Count)
     */
    public function getExecutivePerformance(): array
    {
        return Sale::accessible()
            ->join('users', 'sales.user_id', '=', 'users.id')
            ->selectRaw('users.name, SUM(amount) as total_revenue, COUNT(*) as deals_closed')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_revenue')
            ->get()->toArray();
    }

    /**
     * Get key performance indicators for management.
     */
    public function getQuickStats(): array
    {
        return [
            'first_visits_today' => Visit::accessible()->where('visit_stage', '1st Visit')->whereDate('visit_date', Carbon::today())->count(),
            'pending_followups' => FollowUp::accessible()->whereNull('completed_at')->count(),
            'proposals_sent' => Proposal::accessible()->where('status', 'sent')->count(),
            'total_revenue_mtd' => Sale::accessible()->whereMonth('created_at', Carbon::now()->month)->sum('amount'),
        ];
    }

    /**
     * Get Monthly Revenue Trend for the last 12 months.
     */
    public function getMonthlyRevenueTrend(): array
    {
        return Sale::accessible()
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') as month, SUM(amount) as revenue")
            ->where('created_at', '>=', Carbon::now()->subMonths(12))
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->pluck('revenue', 'month')
            ->toArray();
    }

    /**
     * Simple Revenue Forecast (Based on Pipeline Win Probabilities)
     */
    public function getRevenueForecast(): float
    {
        return Lead::accessible()
            ->join('pipeline_stages', 'leads.stage_id', '=', 'pipeline_stages.id')
            ->join('services', 'leads.service_id', '=', 'services.id')
            ->selectRaw('SUM(pipeline_stages.win_probability / 100 * 5000) as prospective_revenue') 
            ->value('prospective_revenue') ?? 0.0;
    }
}
