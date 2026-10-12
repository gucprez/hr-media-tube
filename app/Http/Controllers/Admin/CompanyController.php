<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit;
use App\Services\CompanyProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Solo super administrador (HR Media System): alta y gestión de empresas clientes. */
class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::withTrashed()->withCount([
            'users' => fn ($q) => $q->withoutGlobalScopes(),
        ])->orderBy('name')->get();

        return view('admin.companies.index', compact('companies'));
    }

    public function create()
    {
        return view('admin.companies.form', ['company' => new Company]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'rnc' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'admin_first_name' => ['required', 'string', 'max:80'],
            'admin_last_name' => ['required', 'string', 'max:80'],
            'admin_username' => ['required', 'alpha_dash', 'max:50', 'unique:users,username'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', Password::min(8)->letters()->numbers()],
        ]);

        $slug = Str::slug($data['name']);
        $i = 2;
        $base = $slug;
        while (Company::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        $company = app(CompanyProvisioner::class)->create([
            'name' => $data['name'], 'slug' => $slug, 'rnc' => $data['rnc'] ?? null, 'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null,
        ], [
            'first_name' => $data['admin_first_name'], 'last_name' => $data['admin_last_name'],
            'username' => $data['admin_username'], 'email' => $data['admin_email'], 'password' => $data['admin_password'],
        ]);

        Audit::log('company.created', "Empresa creada: {$company->name}", $company);

        return redirect()->route('admin.companies.index')->with('success', "Empresa «{$company->name}» creada con su administrador.");
    }

    public function edit(Company $company)
    {
        return view('admin.companies.form', compact('company'));
    }

    public function update(Request $request, Company $company)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'rnc' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $original = $company->getAttributes();
        $company->update($data);
        Audit::changes('updated', $company, $original, "Empresa actualizada: {$company->name}");

        return redirect()->route('admin.companies.index')->with('success', 'Empresa actualizada.');
    }

    public function destroy(Company $company)
    {
        $company->delete();
        Audit::log('company.deleted', "Empresa eliminada: {$company->name}", $company);

        return redirect()->route('admin.companies.index')->with('success', 'Empresa eliminada (puede restaurarse desde base de datos).');
    }

    public function enter(Request $request, Company $company)
    {
        $request->session()->put('active_company_id', $company->id);
        Audit::log('company.entered', "Super admin entró a la empresa {$company->name}", $company);

        return redirect()->route('dashboard');
    }

    public function leave(Request $request)
    {
        $request->session()->forget('active_company_id');

        return redirect()->route('admin.companies.index');
    }
}
