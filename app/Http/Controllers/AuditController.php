<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function __invoke(): Response
    {
        $query = AuditLog::query()->latest('created_at');

        if ($search = request('q')) {
            $query->where(fn ($nested) => $nested
                ->where('actor_email', 'like', "%{$search}%")
                ->orWhere('action_type', 'like', "%{$search}%")
                ->orWhere('resource_id', 'like', "%{$search}%"));
        }

        if ($action = request('action')) {
            $query->where('action_type', $action);
        }

        return Inertia::render('audit/Index', [
            'logs' => $query->paginate(30)->withQueryString(),
            'filters' => request()->only(['q', 'action']),
        ]);
    }
}
