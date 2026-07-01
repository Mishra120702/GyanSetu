<?php
function getTrainerFilters($request) {
    $filters = [
    'search' => '',
    'status' => '',
    'specialization' => '',
    'experience' => '',
    'rating' => '',
    'sort' => 'name_asc'
];
    
    if (isset($request['search']) && !empty($request['search'])) {
        $filters['search'] = trim($request['search']);
    }
    
    if (isset($request['status']) && in_array($request['status'], ['active', 'inactive'])) {
        $filters['status'] = $request['status'];
    }
    
    if (isset($request['specialization']) && !empty($request['specialization'])) {
        $filters['specialization'] = $request['specialization'];
    }
    if (isset($request['experience']) && !empty($request['experience'])) {
    $filters['experience'] = $request['experience'];
}

if (isset($request['rating']) && !empty($request['rating'])) {
    $filters['rating'] = $request['rating'];
}
    
    if (isset($request['sort']) && in_array($request['sort'], ['name_asc', 'name_desc', 'newest', 'oldest'])) {
        $filters['sort'] = $request['sort'];
    }
    
    return $filters;
}