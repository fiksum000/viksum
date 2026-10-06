<?php

namespace App\Http\Controllers;

use App\Models\WaTemplate;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WaTemplateController extends Controller
{
    public function index()
    {
        return view('whatsapp.index', ['templates' => WaTemplate::orderBy('name')->get()]);
    }

    public function update(Request $request, WaTemplate $template)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'body' => 'required|string|max:4000', 'enabled' => 'required|boolean']);
        $template->update($data);
        Audit::log('wa_template.updated', WaTemplate::class, $template->id);
        return back()->with('success', 'Template WhatsApp diperbarui.');
    }
}
