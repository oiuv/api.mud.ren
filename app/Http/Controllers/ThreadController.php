<?php

namespace App\Http\Controllers;

use App\Http\Resources\ThreadResource;
use App\Jobs\ThreadAddPopular;
use App\Services\ThreadSearch;
use App\Thread;
use Illuminate\Http\Request;

class ThreadController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:api', 'active_user'])->except(['index', 'show', 'search']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $threads = Thread::published()
            ->orderByDesc('pinned_at')
            // ->orderByDesc('excellent_at')
            // ->orderByDesc('published_at')
            ->filter($request->all())->paginate($request->get('per_page', 20));

        return ThreadResource::collection($threads);
    }

    public function search(Request $request, ThreadSearch $search)
    {
        $request->validate(['q' => 'nullable|string|max:100', 'query' => 'nullable|string|max:100']);
        $searchTerm = trim($request->input('q', $request->input('query')) ?? '');
        $threads = $search->search($searchTerm)->appends($request->only('q', 'query'));

        return ThreadResource::collection($threads);
    }

    public function report(Request $request, Thread $thread)
    {
        $request->validate([
            'remark' => 'required',
        ]);

        $thread->report()->create([
            'user_id' => auth()->id(),
            'remark' => $request->get('remark'),
        ]);

        return response()->json([]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return ThreadResource
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function store(Request $request)
    {
        $this->authorize('create', Thread::class);
        $this->validate($request, [
            'title' => 'required|min:6|user_unique_content:threads,title',
            'type' => 'required|in:markdown,html',
            'node_id' => 'required|integer|exists:nodes,id',
            'content' => 'required|array',
            'content.body' => 'required_if:type,html|nullable|string',
            'content.markdown' => 'required_if:type,markdown|nullable|string',
            'ticket' => 'required|ticket:publish',
            'is_draft' => 'boolean',
        ]);

        return new ThreadResource($this->saveThread($request, new Thread()));
    }

    /**
     * @return ThreadResource
     */
    public function show(Thread $thread)
    {
        if (!$thread->isPublic() && !optional(auth()->user())->is_admin
            && !(optional(auth()->user())->is_valid && auth()->id() === $thread->user_id && !$thread->banned_at)) {
            abort(404);
        }

        $thread->loadMissing('content');

        if ($thread->isPublic()) {
            // Bypass model write hooks and leave publication/content/timestamps untouched.
            $thread->incrementViews();
            \dispatch(new ThreadAddPopular($thread));
        }

        return new ThreadResource($thread);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return ThreadResource
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function update(Request $request, Thread $thread)
    {
        $this->authorize('update', $thread);

        $rules = [
            'title' => 'required|min:6|user_unique_content:threads,title,'.$thread->id,
            'type' => 'sometimes|in:markdown,html',
            'node_id' => 'sometimes|required|integer|exists:nodes,id',
            'content' => 'sometimes|array',
            'content.body' => 'required_if:type,html|nullable|string',
            'content.markdown' => 'required_if:type,markdown|nullable|string',
            'is_draft' => 'boolean',
        ];

        if (!$request->user()->is_admin) {
            $rules['ticket'] = 'required|ticket:publish';
        }

        $this->validate($request, $rules, [
            'ticket.required' => '请先完成验证',
        ]);

        $this->saveThread($request, $thread);

        return new ThreadResource($thread);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \Exception
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function destroy(Thread $thread)
    {
        $this->authorize('delete', $thread);

        $thread->delete();

        return $this->withNoContent();
    }

    protected function saveThread(Request $request, Thread $thread)
    {
        $attributes = $request->only(array_merge(['title', 'node_id'], Thread::SENSITIVE_FIELDS));
        $request->validate(array_fill_keys(Thread::SENSITIVE_FIELDS, 'sometimes|nullable|date'));

        if (!$thread->exists || $request->has('is_draft')) {
            $attributes['published_at'] = $request->input('is_draft', false)
                ? null : ($thread->published_at ?: now());
        }

        $content = null;
        if ($request->has('content')) {
            $type = $request->input('type', $request->filled('content.markdown') ? 'markdown' : 'html');
            $field = $type === 'html' ? 'body' : 'markdown';
            $request->validate(['content.'.$field => 'required|string']);
            $content = [$field => $request->input('content.'.$field)];
            if ($field === 'body') {
                $content['markdown'] = null;
            }
        }

        return $thread->saveWithContent($attributes, $content);
    }
}
