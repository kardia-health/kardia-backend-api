<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationDetailResource;
use App\Http\Resources\ConversationListResource;
use App\Models\Conversation;
use App\Repositories\ConversationRepository;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;


class ChatController extends Controller
{
    // Inject kedua class yang kita butuhkan
    public function __construct(
        private ChatService $chatService,
        private ConversationRepository $conversationRepository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $conversations = $this->conversationRepository->getUserConversations($request->user());
        return ConversationListResource::collection($conversations)->response();
    }

    public function store(Request $request): JsonResponse
    {
        // ... (validasi message)
        $user = $request->user();
        $userMessage = $request->input('message');

        $conversation = $user->profile->conversations()->create([
            'title' => Str::limit($userMessage, 40),
            'slug' => Str::ulid(),
        ]);

        $aiReply = $this->chatService->getChatResponse($userMessage, $user, $conversation);

        // [PENTING] Hapus cache daftar percakapan karena ada item baru.
        ConversationRepository::forgetUserConversationsCache($user);

        return response()->json(['conversation' => $conversation->only('slug', 'title'), 'reply' => $aiReply], 201);
    }

    public function show(Request $request, Conversation $conversation): ConversationDetailResource
    {
        if ($request->user()->profile->id !== $conversation->user_profile_id) abort(403);

        // Ambil data dari repository, yang mungkin sudah di-cache.
        $cachedConversation = $this->conversationRepository->findBySlug($conversation->slug);

        return new ConversationDetailResource($cachedConversation);
    }

    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        if ($request->user()->profile->id !== $conversation->user_profile_id) abort(403);

        // ... (validasi message) ...

        $aiReply = $this->chatService->getChatResponse($request->input('message'), $request->user(), $conversation);

        // [PENTING] Hapus cache karena ada data baru di percakapan ini
        ConversationRepository::forgetConversationDetailCache($conversation);
        ConversationRepository::forgetUserConversationsCache($request->user());

        return response()->json(['reply' => $aiReply]);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        if ($request->user()->profile->id !== $conversation->user_profile_id) abort(403);
        // ... (validasi title) ...
        $conversation->update(['title' => $request->input('title')]);

        // [PENTING] Hapus cache karena judulnya berubah
        ConversationRepository::forgetConversationDetailCache($conversation);
        ConversationRepository::forgetUserConversationsCache($request->user());

        return response()->json($conversation);
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        if ($request->user()->profile->id !== $conversation->user_profile_id) abort(403);

        // [PENTING] Hapus cache SEBELUM data dihapus
        ConversationRepository::forgetConversationDetailCache($conversation);
        ConversationRepository::forgetUserConversationsCache($request->user());

        $conversation->delete();

        return response()->json(null, 204);
    }
}
