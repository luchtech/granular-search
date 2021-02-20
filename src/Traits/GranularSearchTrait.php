<?php

namespace Luchmewep\GranularSearch\Traits;

use Illuminate\Support\Facades\Schema;

/**
 * * This trait can be used by controller classes to use the Granular Search algorithm.
 * * Granular Search's goal is to make model filtering/searching easier with just one line code.
 *
 * * Most of the time, some keys inside the $request are also column names of the table associated with the $model.
 * * Also, most of the search algorithm created before has a repetitive pattern: $model->where($key, $value).
 * * To save time and apply DRY principle, this trait was born.
 *
 * * By design, this trait will ONLY process the $request keys that are parts of table column names of the $model.
 * * Since the $model is both an input and an output, the $model can be subjected to more filtering before/after using granularSearch.
 * * Since a $request key can have an array as value, whereIn and whereInLike are also added in this algorithm.
 * * The output will be a Query Builder which can be executed using 'get()'.
 *
 * * The parameters are:
 * * $request - Request. It contains all the information regarding the HTTP request.
 * * $model - Model|Query Builder. The model or query builder that will be subjected to searching/filtering.
 * * $table_name - String. Database table name associated with the $model. Get the table name like this: '(new DataRange)->getTable()'
 * * $excluded_keys - String array (optional). If you want specific table columns to be unsearchable, you may specify here.
 * * $like_keys - String array (optional). If you want to filter with LIKE instead of '=' to specific table columns, specify it here.
 *
 * @author James Carlo S. Luchavez (carlo.luchavez@fourello.com)
 */
trait GranularSearchTrait
{
    public function getGranularSearch($request, $model, string $table_name, ?array $excluded_keys = null, ?array $like_keys = null)
    {
        // Step 1: Remove immediately keys that are specified in the $excluded_keys array. (e.g. ['updated_at']; less likely to be searched)
        $data = $request->except($excluded_keys);

        // Step 2: Gets all the keys from the $request. (e.g. name => 'James'; name is the key while 'James' is the value)
        $request_keys = array_keys($data);

        // Step 3: Get all the column names from the table associated with the $model. (e.g. User model is associated with users table)
        $table_keys = Schema::getColumnListing($table_name);

        // Step 4: Get intersection between Table Column Names and Request Keys.
        $common_keys = array_values(array_intersect($request_keys, $table_keys));

        // Step 4.1: Get intersection between Like Keys and Table Keys
        $table_like_intersect_keys = array_values(array_intersect($table_keys, $like_keys));
        $table_like_diff_keys = array_values(array_diff($table_keys, $like_keys, $excluded_keys));

        /**
         * * By default, $common_keys will use '=' instead of LIKE when filtering.
         * * To use LIKE instead of '=', add those keys on $like_keys.
         * * e.g. LIKE searching on 'description' column is more appropriate than using '='.
         */

        // Step 5.1: $like_keys might contain keys that are not inside the $common_keys so remove those keys.
        // Step 5.2: $common_keys should also remove the keys that are already in the $like_keys.
        // Step 5.3: $like_keys can then proceed on filtering the $model using LIKE instead of '='.

        $model = $model->where(function ($query) use ($request, $data, $like_keys, $common_keys, $table_like_intersect_keys, $table_like_diff_keys) {
            if (is_null($like_keys) === FALSE) {
                $like_keys = array_intersect($like_keys, $common_keys);
                $common_keys = array_values(array_diff($common_keys, $like_keys));

                if($request->has('q') && $request->filled('q')){
                    $search = $request->q;
                    foreach ($table_like_intersect_keys as $col) {
                        if(is_array($search)){
                            $query = $query->orWhere(function ($q) use ($search, $col) {
                                foreach ($search as $s) {
                                    $q->orWhere($col, 'LIKE', '%' . $s . '%');
                                }
                            });
                        }else{
                            $query = $query->orWhere($col, 'LIKE', '%' . $search . '%');
                        }
                    }
                }
                else {
                    foreach ($like_keys as $col) {
                        if ($request->filled($col)) {
                            if (is_array($data[$col])) {
                                $query = $query->where(function ($q) use ($data, $col) {
                                    foreach ($data[$col] as $d) {
                                        $q->orWhere($col, 'LIKE', '%' . $d . '%');
                                    }
                                });
                            } else {
                                $query = $query->where($col, 'LIKE', '%' . $data[$col] . '%');
                            }
                        }
                    }
                }
            }

            // Step 6: $common_keys can then proceed to filtering the $query using '='.
            if($request->has('q') && $request->filled('q')){
                $search = $request->q;
                foreach ($table_like_diff_keys as $col) {
                    if(is_array($search)){
                        $query = $query->orWhereIn($col, $search);
                    }else{
                        $query = $query->orWhere($col, $search);
                    }
                }
            }

            else{
                foreach ($common_keys as $col) {
                    if ($request->filled($col)) {
                        if (is_array($data[$col])) {
                            $query = $query->whereIn($col, $data[$col]);
                        } else {
                            $query = $query->where($col, $data[$col]);
                        }
                    }
                }
            }
        });

        // Step 7: (NEW) orderBy
        if($request->has('sortBy') && $request->filled('sortBy')){
            $asc = $request->sortBy;
            if(is_array($asc)){
                foreach ($asc as $a) {
                    if(Schema::hasColumn($table_name, $a)){
                        $model = $model->orderBy($a);
                    }
                }
            }else if(Schema::hasColumn($table_name, $asc)) {
                $model = $model->orderBy($asc);
            }
        }
        else if($request->has('sortByDesc') && $request->filled('sortByDesc')){
            $desc = $request->sortByDesc;
            if(is_array($desc)){
                foreach ($desc as $d) {
                    if(Schema::hasColumn($table_name, $d)){
                        $model = $model->orderBy($d, 'desc');
                    }
                }
            }else if(Schema::hasColumn($table_name, $desc)) {
                $model = $model->orderBy($desc, 'desc');
            }
        }

        // Step 8: return the $model
        return $model;
    }
}
