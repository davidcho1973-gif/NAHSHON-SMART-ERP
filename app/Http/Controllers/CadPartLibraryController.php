<?php

namespace App\Http\Controllers;

use App\Models\CadDrawing;
use App\Services\Drawings\CadPartLibrary;
use Illuminate\Http\Request;

class CadPartLibraryController extends Controller
{
    public function __construct(private readonly CadPartLibrary $library) {}

    public function index(Request $request, CadDrawing $drawing)
    {
        $data = $request->validate(['search' => 'sometimes|nullable|string|max:100', 'after' => 'sometimes|nullable|uuid']);

        return response()->json($this->library->catalog($drawing, $data['search'] ?? '', $data['after'] ?? null));
    }

    public function store(Request $request, CadDrawing $drawing)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'category' => 'required|in:valve,sprinkler,duct,other',
            'specification' => 'sometimes|nullable|string|max:100', 'code' => 'sometimes|nullable|string|max:80', 'geometry' => 'required|array']);

        return response()->json($this->library->create($drawing, $data), 201);
    }

    public function show(CadDrawing $drawing, string $part)
    {
        return response()->json($this->library->payload($this->library->find($drawing, $part)));
    }

    public function archive(CadDrawing $drawing, string $part)
    {
        $this->library->archive($drawing, $part);

        return response()->json(['success' => true]);
    }

    public function quantities(Request $request, CadDrawing $drawing)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);

        return response()->json($this->library->quantities($drawing, $data['revision']));
    }
}
