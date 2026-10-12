<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Services\Audit;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function index()
    {
        return view('fleet.drivers', ['drivers' => Driver::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $driver = Driver::create($this->data($request));
        Audit::log('driver.created', "Chofer creado: {$driver->name}", $driver);

        return back()->with('success', 'Chofer creado.');
    }

    public function update(Request $request, Driver $driver)
    {
        $original = $driver->getAttributes();
        $driver->update($this->data($request) + ['is_active' => $request->boolean('is_active')]);
        Audit::changes('updated', $driver, $original, "Chofer actualizado: {$driver->name}");

        return back()->with('success', 'Chofer actualizado.');
    }

    public function destroy(Driver $driver)
    {
        $driver->delete();
        Audit::log('driver.deleted', "Chofer eliminado: {$driver->name}", $driver);

        return back()->with('success', 'Chofer eliminado (sus furgones conservan el nombre).');
    }

    protected function data(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'license_no' => ['nullable', 'string', 'max:40'],
        ]);
    }
}
