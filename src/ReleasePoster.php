<?php

namespace ErnestDefoe\GitHubReleaseBot;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Flarum\User\UserRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Turns a release into a reply in a discussion.
 *
 * Extracted from the webhook controller so the poller posts through exactly the
 * same path — a release announced because we were told about it and one
 * announced because we noticed it should be indistinguishable in the thread.
 */
class ReleasePoster
{
    public function __construct(
        protected UserRepository $users
    ) {
    }

    /**
     * @throws ModelNotFoundException if the bot user or discussion is missing.
     */
    public function post(
        int $botUserId,
        int $discussionId,
        string $heading,
        string $notes,
        string $url,
        ?string $ip = null
    ): CommentPost {
        $bot = $this->users->findOrFail($botUserId);

        /** @var Discussion $discussion */
        $discussion = Discussion::query()->findOrFail($discussionId);

        $notes = trim($notes);

        if ($notes === '') {
            $notes = '_No release notes provided._';
        }

        $content = "## {$heading}\n\n{$notes}";

        if ($url !== '') {
            $content .= "\n\n[View release on GitHub]({$url})";
        }

        // type + number are auto-assigned by Post::boot(); last_post / comment
        // counts are bumped by DiscussionMetadataUpdater on the Posted event.
        $post = new CommentPost();
        $post->setContentAttribute($content, $bot);
        $post->created_at    = Carbon::now();
        $post->user_id       = $bot->id;
        $post->discussion_id = $discussion->id;
        $post->ip_address    = $ip;
        $post->save();

        // Posted-event listeners that normally refresh discussion stats don't
        // always fire when posts are created outside the JSON:API endpoint,
        // so update them here to keep the thread display in sync.
        $discussion->refreshLastPost();
        $discussion->refreshCommentCount();
        $discussion->refreshParticipantCount();
        $discussion->save();

        return $post;
    }
}
