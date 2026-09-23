<?php

namespace App\Actions\Comments;

use App\Enums\ProjectActivityEvent;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\User;
use App\Services\Projects\ProjectActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Writes the project log and leaves a trace of every change in the history.
 */
class ManageProjectComments
{
    public function __construct(private readonly ProjectActivityLogger $activity) {}

    public function add(User $author, Project $project, string $body, bool $highlighted = false, ?int $taskId = null): ProjectComment
    {
        if ($taskId !== null && ! $project->tasks()->whereKey($taskId)->exists()) {
            throw ValidationException::withMessages(['taskId' => 'La tarea no pertenece a este proyecto.']);
        }

        return DB::transaction(function () use ($author, $project, $body, $highlighted, $taskId): ProjectComment {
            $comment = new ProjectComment(['body' => trim($body), 'is_highlighted' => $highlighted, 'task_id' => $taskId]);
            $comment->project()->associate($project);
            $comment->user()->associate($author);
            $comment->save();

            $this->activity->log($project, ProjectActivityEvent::CommentAdded, Str::limit($comment->body, 140), $comment,
                null, array_filter(['comment_id' => $comment->id, 'highlighted' => $highlighted ?: null]), $author,
            );

            return $comment;
        });
    }

    public function update(ProjectComment $comment, string $body): void
    {
        $body = trim($body);

        if ($body === $comment->body) {
            return;
        }

        DB::transaction(function () use ($comment, $body): void {
            $old = $comment->body;
            $comment->update(['body' => $body]);

            $this->activity->log($comment->project, ProjectActivityEvent::CommentUpdated, Str::limit($body, 140), $comment,
                ['body' => $old], ['body' => $body],
            );
        });
    }

    public function toggleHighlight(ProjectComment $comment): void
    {
        $comment->update(['is_highlighted' => ! $comment->is_highlighted]);
    }

    public function delete(ProjectComment $comment): void
    {
        DB::transaction(function () use ($comment): void {
            $this->activity->log($comment->project, ProjectActivityEvent::CommentDeleted, Str::limit($comment->body, 140), $comment,
                ['body' => $comment->body, 'author_id' => $comment->user_id], null,
            );

            $comment->delete();
        });
    }
}
