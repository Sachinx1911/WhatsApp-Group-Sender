<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Media;
use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

class DemoTemplateSeeder extends Seeder
{
    /** Keys are referenced by DemoCampaignSeeder. */
    public const TEMPLATES = [
        'current_affairs' => [
            'title' => 'आजच्या चालू घडामोडी',
            'category' => 'MPSC',
            'attachment' => 'Current_Affairs_30_09_2026.pdf',
            'tags' => ['current-affairs', 'mpsc', 'daily'],
            'message' => "*📰 आजच्या चालू घडामोडी*\n\nआजच्या महत्त्वाच्या चालू घडामोडी सोबतच्या PDF मध्ये दिल्या आहेत.\nनक्की वाचा आणि मित्रांसोबत शेअर करा. 🙏\n\n_— Education Hub_",
        ],
        'daily_mcq' => [
            'title' => 'Daily MCQ Practice',
            'category' => 'MPSC',
            'attachment' => 'daily_mcq_30_09_2026.png',
            'tags' => ['daily-mcq', 'mpsc'],
            'message' => "*📝 दैनिक MCQ सराव*\n\nआजचे 20 प्रश्न सोडवा.\nवेळ: 15 मिनिटे ⏱️\nउत्तरे उद्या सकाळी 7 वाजता पाठवली जातील.\n\n_— Education Hub_",
        ],
        'police_paper' => [
            'title' => 'पोलीस भरती सराव पेपर',
            'category' => 'Police Bharti',
            'attachment' => 'Police_Bharti_Practice_Paper_05.pdf',
            'tags' => ['police-bharti', 'practice-paper'],
            'message' => "*👮 पोलीस भरती सराव पेपर*\n\nया आठवड्याचा सराव पेपर सोबत जोडला आहे.\nपेपर 90 मिनिटांत सोडवा आणि तुमचे गुण group मध्ये कळवा.\n\n_— Education Hub_",
        ],
        'free_notes' => [
            'title' => 'Free Batch Weekly Notes',
            'category' => 'Free',
            'attachment' => 'Free_Batch_Weekly_Notes.pdf',
            'tags' => ['free', 'notes', 'weekly'],
            'message' => "📚 *या आठवड्याचे मोफत अभ्यास साहित्य*\n\nFree batch साठी या आठवड्याच्या notes सोबत जोडल्या आहेत.\nPremium batch बद्दल माहितीसाठी संपर्क करा.",
        ],
        'premium_live' => [
            'title' => 'Premium Live Class Reminder',
            'category' => 'Premium',
            'attachment' => null,
            'tags' => ['premium', 'live-class'],
            'message' => "*🎓 Premium Batch — Live Class*\n\nआज संध्याकाळी *7:00 वाजता* इतिहास विषयाचा live class आहे.\nवेळेवर join करा. Link class सुरू होण्यापूर्वी 10 मिनिटे आधी पाठवली जाईल.",
        ],
        'holiday_notice' => [
            'title' => 'सुट्टी सूचना',
            'category' => 'Other',
            'attachment' => null,
            'tags' => ['notice'],
            'message' => "*📢 महत्त्वाची सूचना*\n\nउद्या सार्वजनिक सुट्टी असल्यामुळे class होणार नाही.\nपुढील class नेहमीच्या वेळेवर होईल.\n\n_— Education Hub_",
        ],
    ];

    public function run(): void
    {
        $categories = Category::pluck('id', 'name');
        $media = Media::pluck('id', 'original_name');

        foreach (self::TEMPLATES as $template) {
            MessageTemplate::updateOrCreate(['title' => $template['title']], [
                'category_id' => $categories[$template['category']] ?? null,
                'message' => $template['message'],
                'attachment_id' => $template['attachment'] ? $media[$template['attachment']] : null,
                'tags' => $template['tags'],
            ]);
        }
    }
}
