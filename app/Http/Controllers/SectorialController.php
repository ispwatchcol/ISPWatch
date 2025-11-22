<?php

namespace App\Http\Controllers;

use App\Models\Sectorial;
use Illuminate\Http\Request;

class SectorialController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $sectorials = Sectorial::all();

        return response()->json([
            'success' => true,
            'data' => $sectorials,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Sectorial $sectorial)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Sectorial $sectorial)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Sectorial $sectorial)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Sectorial $sectorial)
    {
        //
    }
}
