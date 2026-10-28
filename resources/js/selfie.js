/**
 * The selfie step of the join page and the phone lobby. LIVE CAMERA ONLY: the picture comes from getUserMedia, never from a file input,
 * so there is no way to pick an existing photo. The camera is stopped the moment the shutter is pressed, the panel is cancelled, the
 * page is hidden or the component goes away, so the camera light never stays on.
 *
 * The captured frame is centre-cropped to a square (640px at most), NOT mirrored (only the preview is, like a mirror) and sent as a
 * JPEG blob through Livewire's upload to the component's `photo` property; the server re-checks, resizes and re-encodes it
 * (App\Game\PlayerPhoto). Steps: idle -> camera -> review -> uploading -> idle, or error / denied (with Try again and Skip) / skipped.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lammaSelfie', (messages = {}) => ({
        step: 'idle',
        stream: null,
        ready: false,
        blob: null,
        previewUrl: null,
        progress: 0,
        status: '',
        /** The photo URL that failed to load: the initial shows instead, until a different URL arrives. */
        broken: null,

        /**
         * The Livewire component's state (`photoUrl`, `photoError`, `nickname`), mirrored into this component's own data: a getter that reads $wire
         * directly is not re-run by Alpine when the server changes the value, so each one is copied now and again whenever it changes.
         */
        url: null,
        error: '',
        name: '',

        get photoUrl() {
            return this.url
        },

        get serverError() {
            return this.error
        },

        get nickname() {
            return this.name
        },

        remove() {
            try { this.$wire.removePhoto() } catch (error) { /* no component */ }
        },

        /** The first letter of the nickname being typed (what the avatar shows until there is a photo). */
        get initial() {
            let name = this.nickname.trim()

            return name ? Array.from(name)[0].toUpperCase() : '?'
        },

        init() {
            try {
                let wire = this.$wire
                this.url = wire.photoUrl || null
                this.error = wire.photoError || ''
                this.name = wire.nickname || ''
                wire.$watch('photoUrl', (value) => { this.url = value || null })
                wire.$watch('photoError', (value) => { this.error = value || '' })
                wire.$watch('nickname', (value) => { this.name = value || '' })
            } catch (error) { /* shown outside a Livewire component (the gallery): nothing to mirror */ }

            // A hidden page (another app, a locked screen) must not keep the camera on.
            this.onHide = () => { if (document.hidden) this.cancel() }
            this.onPageHide = () => this.stopCamera()
            document.addEventListener('visibilitychange', this.onHide)
            window.addEventListener('pagehide', this.onPageHide)
        },

        destroy() {
            document.removeEventListener('visibilitychange', this.onHide)
            window.removeEventListener('pagehide', this.onPageHide)
            this.stopCamera()
            this.forget()
        },

        async open() {
            this.error = ''
            try { if (this.$wire.photoError) this.$wire.photoError = '' } catch (error) { /* no component */ }

            if (! window.isSecureContext || ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia) {
                this.step = 'denied'

                return
            }

            this.step = 'camera'
            this.ready = false

            let stream
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: { ideal: 720 }, height: { ideal: 720 } },
                    audio: false,
                })
            } catch (error) {
                if (this.step === 'camera') this.step = 'denied'

                return
            }

            // Cancelled (or the page hid) while the permission prompt was open: let the camera go again.
            if (this.step !== 'camera') {
                stream.getTracks().forEach((track) => track.stop())

                return
            }

            this.stream = stream
            this.$refs.video.srcObject = stream
            try { await this.$refs.video.play() } catch (error) { /* autoplay is on; the first frame still arrives */ }
            this.ready = true
            this.$nextTick(() => this.$refs.shutter && this.$refs.shutter.focus())
        },

        shutter() {
            let video = this.$refs.video
            let width = video.videoWidth
            let height = video.videoHeight
            if (! this.ready || ! width || ! height) return

            let side = Math.min(width, height)
            let size = Math.min(side, 640)
            let canvas = document.createElement('canvas')
            canvas.width = size
            canvas.height = size
            // Drawn straight from the video, so the saved picture is not mirrored even though the preview is.
            canvas.getContext('2d').drawImage(video, (width - side) / 2, (height - side) / 2, side, side, 0, 0, size, size)
            this.stopCamera()

            canvas.toBlob((blob) => {
                if (! blob) {
                    this.step = 'error'

                    return
                }
                this.forget()
                this.blob = blob
                this.previewUrl = URL.createObjectURL(blob)
                this.step = 'review'
                this.$nextTick(() => this.$refs.use && this.$refs.use.focus())
            }, 'image/jpeg', 0.85)
        },

        use() {
            if (! this.blob) return

            try { if (! this.$wire.upload) throw new Error('no upload') } catch (error) {
                this.step = 'error'

                return
            }

            this.step = 'uploading'
            this.progress = 0
            let file = new File([this.blob], 'selfie.jpg', { type: 'image/jpeg' })

            this.$wire.upload('photo', file,
                () => { this.forget(); this.step = 'idle' },
                () => { this.step = 'error'; this.say('failed') },
                (event) => { this.progress = event.detail.progress },
            )
        },

        retake() {
            this.forget()
            this.open()
        },

        /** Close whatever is open and go back to the start. */
        cancel() {
            let wasCamera = this.step === 'camera' || this.step === 'review'
            this.stopCamera()
            this.forget()
            this.step = 'idle'
            if (wasCamera) this.$nextTick(() => this.$refs.take && this.$refs.take.focus())
        },

        skip() {
            this.cancel()
            this.error = ''
            try { this.$wire.photoError = '' } catch (error) { /* no component */ }
            this.step = 'skipped'
        },

        stopCamera() {
            if (this.stream) this.stream.getTracks().forEach((track) => track.stop())
            this.stream = null
            this.ready = false
            if (this.$refs.video) this.$refs.video.srcObject = null
        },

        forget() {
            if (this.previewUrl) URL.revokeObjectURL(this.previewUrl)
            this.previewUrl = null
            this.blob = null
        },

        /** Read out by screen readers ("Photo added", "Photo removed", "Upload failed, try again"). */
        say(kind) {
            this.status = ''
            this.$nextTick(() => { this.status = messages[kind] || '' })
        },
    }))
})

/** The last nickname used on this device, kept in the browser as a suggestion for the join form (never the photo). */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lammaRememberNickname', () => ({
        init() {
            try {
                let last = localStorage.getItem('lamma_nickname')
                if (! this.$el.value && last) {
                    this.$el.value = last
                    this.$el.dispatchEvent(new Event('input', { bubbles: true }))
                }
            } catch (error) { /* storage can be blocked */ }
        },

        remember() {
            try {
                if (this.$el.value.trim()) localStorage.setItem('lamma_nickname', this.$el.value.trim())
            } catch (error) { /* storage can be blocked */ }
        },
    }))
})
