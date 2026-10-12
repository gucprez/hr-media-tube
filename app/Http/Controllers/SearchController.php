<?php

namespace App\Http\Controllers;

use App\Support\Search\GlobalSearch;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request, GlobalSearch $search)
    {
        $term = trim((string) $request->query('q'));
        $groups = $search->search($request->user(), $term, $request->wantsJson() ? 5 : 20);

        if ($request->wantsJson()) {
            return response()->json(collect($groups)->map(fn ($g) => ['label' => $g['label'], 'results' => $g['results']->values()])->values());
        }

        return view('search', ['term' => $term, 'groups' => $groups]);
    }
}
