<?php

namespace App;

use App\Contracts\Commentable;
use App\Jobs\FetchContentMentions;
use App\Jobs\FilterThreadSensitiveWords;
use App\Traits\EsHighlightAttributes;
use App\Traits\OnlyActivatedUserCanCreate;
use App\Traits\WithDiffForHumanTimes;
use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;
use Overtrue\LaravelFollow\Traits\CanBeFavorited;
use Overtrue\LaravelFollow\Traits\CanBeLiked;
use Overtrue\LaravelFollow\Traits\CanBeSubscribed;

/**
 * Class Thread.
 *
 * @author overtrue <i@overtrue.me>
 *
 * @property int            $user_id
 * @property string         $title
 * @property \Carbon\Carbon $excellent_at
 * @property \Carbon\Carbon $pinned_at
 * @property \Carbon\Carbon $frozen_at
 * @property \Carbon\Carbon $banned_at
 * @property bool           $has_pinned
 * @property bool           $has_banned
 * @property bool           $has_excellent
 * @property bool           $has_frozen
 * @property object         $cache
 *
 * @method static \App\Thread published()
 */
class Thread extends Model implements Commentable
{
    use SoftDeletes;
    use Filterable;
    use OnlyActivatedUserCanCreate;
    use WithDiffForHumanTimes;
    use CanBeSubscribed;
    use CanBeFavorited;
    use CanBeLiked;
    use Searchable;
    use EsHighlightAttributes;

    protected $fillable = [
        'user_id', 'title', 'excellent_at', 'node_id',
        'pinned_at', 'frozen_at', 'banned_at', 'published_at', 'cache',
        'cache->views_count', 'cache->comments_count', 'cache->likes_count', 'cache->subscriptions_count',
        'cache->last_reply_user_id', 'cache->last_reply_user_name', 'popular_at',
    ];

    protected $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'is_excellent' => 'bool',
        'cache' => 'array',
    ];

    const CACHE_FIELDS = [
        'views_count' => 0,
        'comments_count' => 0,
        'likes_count' => 0,
        'subscriptions_count' => 0,
        'last_reply_user_id' => 0,
        'last_reply_user_name' => null,
    ];

    const SENSITIVE_FIELDS = [
        'excellent_at', 'pinned_at', 'frozen_at', 'banned_at',
    ];

    protected $dates = [
        'excellent_at', 'pinned_at', 'frozen_at', 'banned_at', 'published_at',
    ];

    protected $with = ['user'];

    protected $appends = [
        'has_pinned', 'has_banned', 'has_excellent', 'has_frozen',
        'created_at_timeago', 'updated_at_timeago', 'has_liked',
        'has_subscribed',
    ];

    const POPULAR_CONDITION_LIKES_COUNT = 15;

    const POPULAR_CONDITION_VIEWS_COUNT = 200;

    const POPULAR_CONDITION_COMMENTS_COUNT = 10;

    const THREAD_SENSITIVE_TRIGGER_LIMIT = 5;

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Thread $thread) {
            $thread->user_id = \auth()->id();

            static::throttleCheck($thread->user);
        });

        static::saving(function (Thread $thread) {
            if (array_intersect(array_keys($thread->getDirty()), self::SENSITIVE_FIELDS)
                && !optional(auth()->user())->is_admin) {
                abort(403, '非法请求！');
            }

            if ($thread->isDirty('title')) {
                $thread->title = \dispatch_now(new FilterThreadSensitiveWords($thread->title));
            }
        });

        static::created(function (Thread $thread) {
            $thread->user->refreshCache();
        });
    }

    public function saveWithContent(array $attributes, ?array $content = null)
    {
        static::withoutSyncingToSearch(function () use ($attributes, $content) {
            DB::transaction(function () use ($attributes, $content) {
                $creating = !$this->exists;
                $publishing = !$this->published_at && !empty($attributes['published_at']);
                $this->fill($attributes)->save();

                if ($content !== null) {
                    foreach ($content as $field => $value) {
                        if ($value !== null) {
                            $content[$field] = \dispatch_now(new FilterThreadSensitiveWords($value));
                        }
                    }
                    $savedContent = $this->content()->firstOrNew([]);
                    $savedContent->deferMentions = true;
                    $savedContent->fill($content)->save();
                    $savedContent->deferMentions = false;
                    $this->setRelation('content', $savedContent);
                }

                if ($creating) {
                    $this->user->increment('energy', User::ENERGY_THREAD_CREATE);
                }
                if ($publishing && !Activity::where('log_name', 'published.thread')
                    ->where('subject_type', self::class)->where('subject_id', $this->id)->exists()) {
                    \activity('published.thread')
                        ->performedOn($this)
                        ->withProperty('content', \str_limit(\strip_tags(optional($this->content)->body), 200))
                        ->log('发布帖子');
                }
            });
        });

        if ($content !== null) {
            \dispatch(new FetchContentMentions($this->content));
        }

        // Index only after the body and the transaction have been saved.
        $this->shouldBeSearchable() ? $this->searchable() : $this->unsearchable();

        return $this;
    }

    public function shouldBeSearchable()
    {
        return $this->published_at && $this->published_at->lte(now())
            && !$this->banned_at && !$this->trashed() && optional($this->user)->is_valid;
    }

    public function incrementViews()
    {
        // Atomic JSON update supports both MySQL and SQLite, including a NULL cache.
        $this->newQuery()->whereKey($this->id)->toBase()->update([
            'cache' => DB::raw("JSON_SET(COALESCE(cache, '{}'), '$.views_count', COALESCE(JSON_EXTRACT(cache, '$.views_count'), 0) + 1)"),
        ]);
        $this->cache = array_merge($this->cache, ['views_count' => $this->cache['views_count'] + 1]);
    }

    public function toSearchableArray()
    {
        $content = $this->content ? $this->content->markdown : '';

        return array_merge(\array_except($this->toArray(), 'user'), \compact('content'));
    }

    public function searchableType()
    {
        return 'App\Thread';
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function report()
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    public function content()
    {
        return $this->morphOne(Content::class, 'contentable');
    }

    public function node()
    {
        return $this->belongsTo(Node::class);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function scopePublished($query)
    {
        $query->where('published_at', '<=', now())
            ->whereNull('banned_at')
            ->whereHas('user', function ($q) {
                $q->whereNotNull('activated_at')->whereNull('banned_at');
            });
    }

    public function getCacheAttribute()
    {
        return array_merge(self::CACHE_FIELDS, json_decode($this->attributes['cache'] ?? '{}', true));
    }

    public function getHasPinnedAttribute()
    {
        return (bool) $this->pinned_at;
    }

    public function getHasBannedAttribute()
    {
        return (bool) $this->banned_at;
    }

    public function getHasExcellentAttribute()
    {
        return (bool) $this->excellent_at;
    }

    public function getHasFrozenAttribute()
    {
        return (bool) $this->frozen_at;
    }

    public function getHasSubscribedAttribute()
    {
        if (auth()->guest()) {
            return false;
        }

        return $this->relationLoaded('subscribers') ? $this->subscribers->contains(auth()->user()) : $this->isSubscribedBy(auth()->user());
    }

    public function getHasLikedAttribute()
    {
        if (auth()->guest()) {
            return false;
        }

        return $this->relationLoaded('likers') ? $this->likers->contains(auth()->user()) : $this->isLikedBy(auth()->user());
    }

    public function getUrlAttribute()
    {
        return \sprintf('%s/threads/%d', \config('app.site_url'), $this->id);
    }

    /**
     * @param \App\Comment $lastComment
     *
     * @throws \Exception
     *
     * @return mixed
     */
    public function afterCommentCreated(Comment $lastComment)
    {
        $lastComment->user->subscribe($this);
    }

    public function refreshCache()
    {
        $lastComment = $this->comments()->latest()->first();

        $this->update(['cache' => \array_merge(self::CACHE_FIELDS, [
            'views_count' => $this->cache['views_count'],
            'comments_count' => $this->comments()->count(),
            'likes_count' => $this->likers()->count(),
            'favoriters_count' => $this->favoriters()->count(),
            'subscriptions_count' => $this->subscribers()->count(),
            'last_reply_user_id' => $lastComment ? $lastComment->user->id : 0,
            'last_reply_user_name' => $lastComment ? $lastComment->user->name : '',
        ])]);
    }

    public static function throttleCheck(User $user)
    {
        $lastThread = $user->threads()->latest()->first();

        if ($lastThread && $lastThread->created_at->gt(now()->subMinutes(config('throttle.thread.create')))) {
            \abort(403, '发贴太频繁');
        }
    }
}
