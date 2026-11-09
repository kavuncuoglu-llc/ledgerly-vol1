const CONNECT_TIMEOUT_MS = 4000;

const SERVER_EVENTS = [
    'ReplyDelta',
    'ToolCallStarted',
    'ReplyRestarted',
    'ReplyPaused',
    'ReplyCompleted',
    'ReplyFailed',
];

export const readCookie = (name) => {
    const match = document.cookie
        .split('; ')
        .find((pair) => pair.startsWith(`${name}=`));

    if (!match) {
        return '';
    }

    return decodeURIComponent(match.slice(name.length + 1));
};

export class ReplyChannel {
    constructor({ replyId, reverb, websocketsEnabled }) {
        this.replyId = replyId;
        this.reverb = reverb;
        this.websocketsEnabled = websocketsEnabled;
        this.handlers = {};
        this.socket = null;
        this.source = null;
        this.connectTimer = null;
        this.closed = false;
        this.subscribed = false;
        this.nextSequence = 0;
        this.transport = '';
    }

    on(event, handler) {
        this.handlers[event] = handler;

        return this;
    }

    open(afterSequence = 0) {
        this.closed = false;
        this.nextSequence = afterSequence;

        if (!this.websocketsEnabled) {
            this.openStream();

            return;
        }

        this.openSocket();
    }

    close() {
        this.closed = true;

        window.clearTimeout(this.connectTimer);

        if (this.socket) {
            this.socket.close();
            this.socket = null;
        }

        if (this.source) {
            this.source.close();
            this.source = null;
        }
    }

    emit(event, payload = {}) {
        const handler = this.handlers[event];

        if (handler) {
            handler(payload);
        }
    }

    openSocket() {
        const scheme = this.reverb.scheme === 'https'
            ? 'wss'
            : 'ws';

        const query = 'protocol=7&client=ledgerly&version=1.0';

        const url = `${scheme}://${this.reverb.host}:${this.reverb.port}/app/${this.reverb.key}?${query}`;

        this.transport = 'websocket';
        this.subscribed = false;
        this.socket = new WebSocket(url);

        this.connectTimer = window.setTimeout(() => this.fallBack(), CONNECT_TIMEOUT_MS);

        this.socket.addEventListener('message', (message) => this.handleSocketMessage(message));
        this.socket.addEventListener('error', () => this.handleSocketLoss());
        this.socket.addEventListener('close', () => this.handleSocketLoss());
    }

    handleSocketLoss() {
        if (this.closed) {
            return;
        }

        if (!this.subscribed) {
            this.fallBack();

            return;
        }

        this.socket = null;
        this.emit('disconnected');
    }

    fallBack() {
        if (this.closed) {
            return;
        }

        window.clearTimeout(this.connectTimer);

        if (this.socket) {
            this.socket.close();
            this.socket = null;
        }

        this.openStream();
    }

    async handleSocketMessage(message) {
        const frame = JSON.parse(message.data);

        if (frame.event === 'pusher:connection_established') {
            await this.subscribe(JSON.parse(frame.data).socket_id);

            return;
        }

        if (frame.event === 'pusher:ping') {
            this.socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));

            return;
        }

        if (frame.event === 'pusher_internal:subscription_succeeded') {
            this.subscribed = true;
            window.clearTimeout(this.connectTimer);
            this.emit('subscribed', { transport: this.transport });

            return;
        }

        if (SERVER_EVENTS.includes(frame.event)) {
            this.emit(frame.event, JSON.parse(frame.data));
        }
    }

    async subscribe(socketId) {
        const channel = `private-reply.${this.replyId}`;

        const response = await fetch('/broadcasting/auth', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
            },
            body: new URLSearchParams({ socket_id: socketId, channel_name: channel }),
        });

        if (!response.ok) {
            this.fallBack();

            return;
        }

        const { auth } = await response.json();

        this.socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth, channel } }));
    }

    openStream() {
        this.transport = 'sse';

        this.source = new EventSource(`/replies/${this.replyId}/stream?after=${this.nextSequence}`);

        SERVER_EVENTS.forEach((event) => {
            this.source.addEventListener(event, (message) => this.handleStreamEvent(event, message));
        });

        this.source.addEventListener('open', () => this.emit('subscribed', { transport: this.transport }));
    }

    handleStreamEvent(event, message) {
        const payload = JSON.parse(message.data);

        if (event === 'ReplyDelta') {
            this.nextSequence = payload.n + 1;
        }

        this.emit(event, payload);
    }
}
