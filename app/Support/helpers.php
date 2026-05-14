<?php 

    if(!function_exists('only_numbers')){
        function only_numbers(?string $value){
            return preg_replace('/\D/', '', $value ?? '');
        }
    }