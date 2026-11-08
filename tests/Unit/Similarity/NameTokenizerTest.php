<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Similarity\NameTokenizer;

it('splits every naming style into the same tokens', function (string $name) {
    expect((new NameTokenizer)->tokenize($name)->tokens)->toBe(['active', 'user']);
})->with(['active_users', 'activeUsers', 'ActiveUsers', 'active-users', 'ACTIVE_USERS', 'active.users', 'active users', 'active:users']);

it('keeps the words as written next to the singular tokens', function () {
    $name = (new NameTokenizer)->tokenize('activeUsers');

    expect($name->original)->toBe('activeUsers')
        ->and($name->words)->toBe(['active', 'users'])
        ->and($name->tokens)->toBe(['active', 'user'])
        ->and($name->normalized())->toBe('active_user');
});

it('splits acronyms from the following word', function () {
    expect((new NameTokenizer)->tokenize('HTTPResponseCodes')->tokens)->toBe(['http', 'response', 'code']);
});

it('uses English singular rules without stemming', function () {
    expect((new NameTokenizer)->tokenize('people_categories_indices_data_status_activity')->tokens)
        ->toBe(['person', 'category', 'index', 'data', 'status', 'activity']);
});

it('keeps letters and digits together and drops nothing', function () {
    expect((new NameTokenizer)->tokenize('oauth2_user_list_data')->tokens)->toBe(['oauth2', 'user', 'list', 'data']);
});
