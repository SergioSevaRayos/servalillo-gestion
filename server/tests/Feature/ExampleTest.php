<?php

it('redirects the root to the login page when there is no session', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});
