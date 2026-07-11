<?php

beforeEach(function () {
    config(['majordom.token' => 'secret-token']);
});

test('unauthenticated GET / redirects to /login', function () {
    $this->get('/')->assertRedirect('/login');
});

test('unauthenticated JSON GET / gets 401', function () {
    $this->getJson('/')->assertStatus(401);
});

test('GET / with correct Bearer token returns 200', function () {
    $this->withHeaders(['Authorization' => 'Bearer secret-token'])
        ->get('/')
        ->assertStatus(200);
});

test('POST /login with correct token redirects to / and subsequent GET / is 200', function () {
    $this->post('/login', ['token' => 'secret-token'])->assertRedirect('/');
    $this->get('/')->assertStatus(200);
});

test('POST /login with wrong token returns to login with error and session not authenticated', function () {
    $this->post('/login', ['token' => 'wrong-token'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['token']);
    expect(session()->get('majordom_authenticated'))->not()->toBeTrue();
});

test('when majordom.token is null GET / returns 503', function () {
    config(['majordom.token' => null]);
    $this->get('/')->assertStatus(503);
});

test('GET /login renders 200 without any auth', function () {
    $this->get('/login')->assertStatus(200);
});
