<?php
/** Tourfecto - CRM Note Service @version 1.0.0 */
class CrmNoteService {
    public function create(int $userId, array $data): CrmNote {
        if (empty($data['body'])) {
            throw new Exception('نص الملاحظة مطلوب');
        }
        $note = new CrmNote([
            'user_id' => $userId,
            'author_user_id' => $userId,
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'body' => $data['body'],
            'pinned' => !empty($data['pinned']) ? 1 : 0,
        ]);
        $note->save();

        ActivityLog::record('crm', 'note.created', [
            'user_id' => $userId, 'subject_type' => 'crm_notes', 'subject_id' => (int) $note->getAttribute('id'),
        ]);

        return $note;
    }

    public function forRelated(string $relatedType, int $relatedId): array {
        return (new CrmNote())->forRelated($relatedType, $relatedId);
    }
}
