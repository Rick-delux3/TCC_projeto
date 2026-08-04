<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\StoreCompanyRequest as PublicStoreCompanyRequest;
use App\Models\Corretor;
use Illuminate\Support\Facades\Gate;

class StoreCompanyRequest extends PublicStoreCompanyRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $corretor = $this->user('admin');

        return $corretor instanceof Corretor
            && Gate::forUser($corretor)->allows('create-real-estate-company');
    }
}
