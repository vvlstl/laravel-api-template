<?php

it('returns JSON 404 for unknown api route', function () {
    $this->getJson('/api/v1/nope')
        ->assertNotFound()
        ->assertJsonStructure(['message', 'code', 'errors']);
});

it('returns JSON 401 for guest on protected route', function () {
    $this->getJson('/api/v1/user')
        ->assertUnauthorized()
        ->assertJsonStructure(['message', 'code', 'errors']);
});

it('sends CORS header for frontend origin', function () {
    $this->withHeaders(['Origin' => 'http://localhost:3000'])
        ->getJson('/api/v1/user')
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
});

it('sets csrf cookie', function () {
    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});
