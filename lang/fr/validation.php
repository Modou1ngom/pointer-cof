<?php

return [

    'accepted' => 'Le champ :attribute doit être accepté.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'unique' => 'La valeur du champ :attribute est déjà utilisée.',

    'min' => [
        'array' => 'Le champ :attribute doit avoir au moins :min éléments.',
        'file' => 'Le champ :attribute doit faire au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],

    'max' => [
        'array' => 'Le champ :attribute ne doit pas avoir plus de :max éléments.',
        'file' => 'Le champ :attribute ne doit pas dépasser :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne doit pas être supérieur à :max.',
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],

    'password' => [
        'letters' => 'Le :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le :attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Ce :attribute apparaît dans une fuite de données. Choisissez-en un autre.',
    ],

    'attributes' => [
        'password' => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
        'compte_password' => 'mot de passe',
        'compte_password_confirmation' => 'confirmation du mot de passe',
        'email' => 'e-mail',
    ],

];
