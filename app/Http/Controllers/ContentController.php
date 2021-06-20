<?php

namespace App\Http\Controllers;

use App\Content;
use App\Http\Resources\ContentResource;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    public function preview(Request $request)
    {
        return Content::toHTML($request->get('markdown'));
    }


    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Content             $content
     *
     * @return \App\Http\Resources\ContentResource
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function update(Request $request, Content $content)
    {
        $this->authorize('update', $content);

        $this->validate($request, [
            'markdown' => 'required',
        ]);

        $content->update($request->all());

        return new ContentResource($content);
    }
}
