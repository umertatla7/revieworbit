# Personalized media editing

The media editor supports replacing the background photo while keeping the same media template and message-template links. Select **Edit**, choose **Replace photo**, adjust the writing area, and save changes. Leaving the upload empty keeps the existing photo. Replacement uploads use the same image type, size, and dimension validation as creation; the stored dimensions and private image path are updated together. The audit event records whether the background changed. Previously generated images are not re-rendered by an edit.

Deleting a message template archives it. Archived message templates no longer block media deletion; their media links are cleared when the media is deleted. Draft and active message templates still protect media they reference. Both replacement and deletion are scoped to the authenticated business. Deleting media currently open in the editor resets the form.

Regression coverage in `MilestoneSixTest` checks replacement persistence, invalid image rejection, keeping the background when no new file is supplied, tenant isolation, and deletion after archiving a linked message template.
