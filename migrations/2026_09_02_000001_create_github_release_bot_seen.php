<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * What each watched repository was last seen at.
 *
 * 🚨 This table is what stops the first poll of a repo dumping its entire
 * release history into a discussion. On first sight we record the newest
 * release and post nothing; only what appears AFTER that is announced.
 */
return Migration::createTable('github_release_bot_seen', function (Blueprint $table) {
    // owner/repo — the full path, because watched repositories are other
    // people's and the bare name is not unique across owners.
    $table->string('repo', 190)->primary();
    $table->string('last_release_id', 190)->nullable();
    $table->string('last_tag', 100)->nullable();
    $table->dateTime('last_checked_at')->nullable();
    $table->dateTime('last_posted_at')->nullable();
    // A repo whose feed keeps failing should say so somewhere visible rather
    // than silently going quiet.
    $table->unsignedSmallInteger('failures')->default(0);
    $table->string('last_error', 255)->nullable();
});
