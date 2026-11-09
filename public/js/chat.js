import { ReplyChannel, readCookie } from '/js/reply-channel.js';

const RECONNECT_DELAY_MS = 1000;
const MAX_RECONNECTS = 5;

const config = JSON.parse(document.getElementById('chat-config').textContent);

const log = document.getElementById('chat-log');
const form = document.getElementById('chat-form');
const input = document.getElementById('chat-input');
const sendButton = document.getElementById('chat-send');
const loginForm = document.getElementById('login-form');

let conversationId = null;

const requestJson = async (method, url, body = null) => {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
        },
        body: body === null
            ? null
            : JSON.stringify(body),
    });

    return { ok: response.ok, status: response.status, body: await response.json() };
};

const element = (tag, className, text = '') => {
    const node = document.createElement(tag);

    node.className = className;
    node.textContent = text;

    return node;
};

const scrollToEnd = () => {
    log.scrollTop = log.scrollHeight;
};

class MessageView {
    constructor(question, onRetry) {
        this.onRetry = onRetry;
        this.onApprovalAnswered = () => {};
        this.question = question;
        this.chunks = [];
        this.root = element('div', 'chat-message chat-assistant is-pending');
        this.status = element('div', 'chat-status', 'Thinking...');
        this.text = element('div', 'chat-text');
        this.extras = element('div', 'chat-extras');

        this.root.append(this.status, this.text, this.extras);
        log.append(element('div', 'chat-message chat-user', question), this.root);
        scrollToEnd();
    }

    setState(state) {
        this.root.className = `chat-message chat-assistant is-${state}`;
    }

    showStatus(text) {
        this.status.textContent = text;
    }

    hasGapBefore(sequence) {
        for (let index = 0; index < sequence; index += 1) {
            if (this.chunks[index] === undefined) {
                return true;
            }
        }

        return false;
    }

    addDelta(sequence, text) {
        this.chunks[sequence] = text;
        this.text.textContent = this.chunks.join('');
        this.setState('streaming');
        scrollToEnd();
    }

    replaceChunks(chunks) {
        this.chunks = chunks;
        this.text.textContent = chunks.join('');
    }

    restart() {
        this.chunks = [];
        this.text.textContent = '';
        this.extras.replaceChildren();
        this.showStatus('Thinking...');
        this.setState('pending');
    }

    showPaused(summary, approvals) {
        this.setState('paused');
        this.extras.replaceChildren();

        approvals.forEach((approval) => this.extras.append(this.approvalCard(approval)));
        this.showStatus(summary);
        scrollToEnd();
    }

    approvalCard(approval) {
        const card = element('div', 'chat-approval');
        const approve = element('button', 'chat-button', 'Approve');
        const decline = element('button', 'chat-button chat-button-quiet', 'Decline');

        card.append(element('p', 'chat-approval-summary', approval.summary), approve, decline);

        const answer = async (url) => {
            approve.disabled = true;
            decline.disabled = true;

            await requestJson('POST', new URL(url).pathname);

            card.remove();
            this.setState('pending');
            this.showStatus('Continuing...');
            this.onApprovalAnswered();
        };

        approve.addEventListener('click', () => answer(approval.approve_url));
        decline.addEventListener('click', () => answer(approval.decline_url));

        return card;
    }

    showCompleted(reply) {
        this.setState('completed');
        this.text.textContent = reply.content;
        this.extras.replaceChildren();

        if (!reply.citations_ok) {
            this.extras.append(element('p', 'chat-warning', 'This answer could not be checked against the help center.'));
        }

        this.extras.append(this.sourceList(reply.sources), this.feedbackButtons(reply));
        scrollToEnd();
    }

    sourceList(sources) {
        const list = element('ul', 'chat-sources');

        sources.forEach((source) => {
            const item = document.createElement('li');
            const link = element('a', 'chat-source', source.label);

            link.href = source.url;
            item.append(link);
            list.append(item);
        });

        return list;
    }

    feedbackButtons(reply) {
        const bar = element('div', 'chat-feedback');

        [['up', 'Helpful'], ['down', 'Not helpful']].forEach(([value, label]) => {
            const button = element('button', 'chat-button chat-button-quiet', label);

            button.disabled = reply.feedback === value;

            button.addEventListener('click', async () => {
                await requestJson('POST', `/replies/${reply.id}/feedback`, { feedback: value });

                bar.querySelectorAll('button').forEach((other) => {
                    other.disabled = other === button;
                });
            });

            bar.append(button);
        });

        return bar;
    }

    showFailed(message) {
        this.setState('failed');
        this.text.textContent = message;
        this.extras.replaceChildren();

        const retry = element('button', 'chat-button', 'Try again');

        retry.addEventListener('click', () => {
            retry.disabled = true;
            this.onRetry(this.question);
        });

        this.extras.append(retry);
    }
}

class ReplyTracker {
    constructor(replyId, view) {
        this.replyId = replyId;
        this.view = view;
        this.reconnects = 0;
        this.channel = null;
        this.recovering = false;
        this.view.onApprovalAnswered = () => this.resumeAfterApproval();
    }

    start() {
        this.connect(0);
    }

    resumeAfterApproval() {
        if (this.channel.closed) {
            this.connect(0);
        }
    }

    pause(event) {
        this.view.showPaused(event.summary, event.approvals);

        if (this.channel.transport === 'sse') {
            this.channel.close();
        }
    }

    connect(afterSequence) {
        this.channel = new ReplyChannel({
            replyId: this.replyId,
            reverb: config.reverb,
            websocketsEnabled: config.websocketsEnabled,
        });

        this.channel
            .on('subscribed', () => this.recover())
            .on('disconnected', () => this.reconnect())
            .on('ToolCallStarted', (event) => this.view.showStatus(event.label))
            .on('ReplyDelta', (event) => this.receiveDelta(event))
            .on('ReplyRestarted', () => this.view.restart())
            .on('ReplyPaused', (event) => this.pause(event))
            .on('ReplyCompleted', () => this.finish())
            .on('ReplyFailed', (event) => this.fail(event.message));

        this.channel.open(afterSequence);
    }

    receiveDelta(event) {
        const gap = this.view.hasGapBefore(event.n);

        this.view.addDelta(event.n, event.t);

        if (gap) {
            this.recover();
        }
    }

    async recover() {
        if (this.recovering) {
            return;
        }

        this.recovering = true;

        const { body } = await requestJson('GET', `/replies/${this.replyId}`);

        this.recovering = false;
        this.reconnects = 0;
        this.apply(body.data);
    }

    apply(reply) {
        if (reply.status === 'completed') {
            this.view.showCompleted(reply);
            this.channel.close();

            return;
        }

        if (reply.status === 'failed') {
            this.view.showFailed(reply.content);
            this.channel.close();

            return;
        }

        if (reply.status === 'expired') {
            this.view.showFailed(reply.content);
            this.channel.close();

            return;
        }

        if (reply.status === 'paused') {
            this.pause({ summary: reply.paused_for, approvals: reply.approvals });

            return;
        }

        if (reply.chunks.length > 0) {
            this.view.replaceChunks(reply.chunks);
            this.view.setState('streaming');
        }
    }

    async finish() {
        const { body } = await requestJson('GET', `/replies/${this.replyId}`);

        this.view.showCompleted(body.data);
        this.channel.close();
    }

    fail(message) {
        this.view.showFailed(message);
        this.channel.close();
    }

    reconnect() {
        if (this.reconnects >= MAX_RECONNECTS) {
            this.fail('The connection was lost. Reload the page to see the reply.');

            return;
        }

        this.reconnects += 1;

        window.setTimeout(() => this.connect(0), RECONNECT_DELAY_MS);
    }
}

const submit = async (question) => {
    const view = new MessageView(question, submit);

    const payload = {
        question,
        request_id: crypto.randomUUID(),
    };

    if (conversationId !== null) {
        payload.conversation_id = conversationId;
    }

    const { ok, body } = await requestJson('POST', '/support/ask', payload);

    if (!ok) {
        view.showFailed(body.message);

        return;
    }

    conversationId = body.conversation_id;

    new ReplyTracker(body.reply_id, view).start();
};

if (form) {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const question = input.value.trim();

        if (question === '') {
            return;
        }

        input.value = '';
        sendButton.disabled = true;

        await submit(question);

        sendButton.disabled = false;
        input.focus();
    });
}

if (loginForm) {
    loginForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const data = new FormData(loginForm);

        const { ok } = await requestJson('POST', '/login', {
            email: data.get('email'),
            password: data.get('password'),
        });

        if (!ok) {
            document.getElementById('login-error').hidden = false;

            return;
        }

        window.location.reload();
    });
}
