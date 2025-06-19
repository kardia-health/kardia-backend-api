<?php

/**
 * Pemetaan Negara ke Kategori Risiko SCORE2.
 * Digunakan untuk menentukan parameter kalibrasi secara otomatis.
 * Semua nama negara sengaja dibuat huruf kecil untuk pencocokan yang case-insensitive.
 */
return [
  'very_high' => [
    'afghanistan',
    'east timor',
    'indonesia',
    'iraq',
    'kyrgyzstan',
    'laos',
    'mongolia',
    'myanmar',
    'north korea',
    'oman',
    'pakistan',
    'philippines',
    'saudi arabia',
    'tajikistan',
    'turkmenistan',
    'uzbekistan',
    'yemen',
  ],

  'high' => [
    'bahrain',
    'bangladesh',
    'bhutan',
    'brunei',
    'cambodia',
    'china',
    'india',
    'iran',
    'jordan',
    'kuwait',
    'malaysia',
    'nepal',
    'qatar',
    'taiwan',
    'united arab emirates',
    'vietnam',
  ],

  'moderate' => [
    'sri lanka',
    'thailand',
  ],

  'low' => [
    'japan',
    'singapore',
    'south korea',
  ],
];
