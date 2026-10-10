<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\Request;
use LapFund\Http\Response;

/** Participant pool across all events (F16: visible to all admins, name and e-mail). */
final class PoolController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $this->admin('pool.view');
        $query = mb_substr($request->input('q'), 0, 100);

        return $this->render('admin/pool.html.twig', [
            'query' => $query,
            'rows' => $this->app->participants()->searchPool($query, null, 300),
            'total' => $this->app->participants()->poolCount(),
        ]);
    }
}
