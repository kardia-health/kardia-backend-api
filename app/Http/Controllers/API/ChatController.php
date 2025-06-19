<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationDetailResource;
use App\Http\Resources\ConversationListResource;
use App\Models\Conversation;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(private ChatService $chatService) {}

    /**
     * [GET /conversations] - Mengambil daftar semua percakapan.
     */
    public function index(Request $request): JsonResponse
    {
        $conversations = $request->user()->profile->conversations()
            ->with('chatMessages:id,conversation_id,content') // Eager load untuk efisiensi
            ->latest('updated_at') // Urutkan berdasarkan yang terakhir di-update
            ->get();

        // Gunakan Resource Collection untuk memformat setiap item
        return ConversationListResource::collection($conversations)->response();
    }

    /**
     * [POST /conversations] - Memulai percakapan baru dengan pesan pertama.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['message' => 'required|string|max:2000']);
        if ($validator->fails()) return response()->json($validator->errors(), 422);

        $user = $request->user();
        $userMessage = $request->input('message');

        $conversation = $user->profile->conversations()->create([
            'title' => Str::limit($userMessage, 40),
            'slug' => Str::ulid(),
        ]);

        // Panggil service untuk mendapatkan balasan LENGKAP
        $aiReply = $this->chatService->getChatResponse($userMessage, $user, $conversation);

        return response()->json([
            'conversation' => $conversation->only('slug', 'title'),
            'reply' => $aiReply
        ], 201);
    }

    /**
     * [GET /conversations/{slug}] - Menampilkan riwayat pesan dari satu percakapan.
     */
    public function show(Request $request, Conversation $conversation): ConversationDetailResource
    {
        if ($request->user()->profile->id !== $conversation->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        // Muat relasi pesan agar tidak ada query N+1
        $conversation->load('chatMessages');

        return new ConversationDetailResource($conversation);
    }

    /**
     * [POST /conversations/{conversation:slug}/messages] - Mengirim pesan lanjutan.
     */
    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        if ($request->user()->profile->id !== $conversation->user_profile_id) abort(403);

        $validator = Validator::make($request->all(), ['message' => 'required|string|max:2000']);
        if ($validator->fails()) return response()->json($validator->errors(), 422);

        // Panggil service untuk mendapatkan balasan LENGKAP
        $aiReply = $this->chatService->getChatResponse(
            $request->input('message'),
            $request->user(),
            $conversation
        );

        return response()->json(['reply' => $aiReply]);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        // 1. Otorisasi: Pastikan pengguna hanya bisa mengedit miliknya.
        if ($request->user()->profile->id !== $conversation->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        // 2. Validasi: Pastikan judul yang dikirim tidak kosong dan tidak terlalu panjang.
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // 3. Lakukan Operasi Update
        $conversation->update([
            'title' => $request->input('title'),
        ]);

        // 4. Kembalikan data percakapan yang sudah diperbarui
        return response()->json($conversation);
    }

    /**
     * [DELETE /conversations/{slug}] - Menghapus sebuah percakapan.
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        // Otorisasi
        if ($request->user()->profile->id !== $conversation->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }
        $conversation->delete();
        return response()->json(null, 204);
    }
}
