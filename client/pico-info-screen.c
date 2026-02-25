#include <stdlib.h>
#include <stdint.h>
#include <stdbool.h>
#include "pico/stdlib.h"
#include "pico/cyw43_arch.h"
#include "waveshare-pico-epaper/EPD_3in7.h"
#include "pico/async_context.h"
#include "pico-info-screen-config.h"

// these are all meant to be set, by you, in pico-info-screen-config.h
#ifndef HOST
#error Please define HOST in pico-info-screen-config.h
#endif
#ifndef URL_REQUEST
#error Please define URL_REQUEST in pico-info-screen-config.h
#endif
#ifndef WIFI_SSID
#error Please define WIFI_SSID in pico-info-screen-config.h
#endif
#ifndef WIFI_PASSWORD
#error Please define WIFI_PASSWORD in pico-info-screen-config.h
#endif
#ifndef ZIPCODE
#error Please define ZIPCODE in pico-info-screen-config.h
#endif
#ifndef USE_TLS
#error Please define USE_TLS in pico-info-screen-config.h
#endif

#ifndef PORT
    #if USE_TLS
        #define PORT 443
    #else
        #define PORT 80
    #endif
#endif

#include "lwip/apps/http_client.h"

#if USE_TLS
#include "lwip/altcp_tls.h"
#endif

// Pico W devices use a GPIO on the WIFI chip for the LED,
// so when building for Pico W, CYW43_WL_GPIO_LED_PIN will be defined
#ifdef CYW43_WL_GPIO_LED_PIN
#include "pico/cyw43_arch.h"
#endif

static int pico_led_init(void) {
#if defined(PICO_DEFAULT_LED_PIN)
    // A device like Pico that uses a GPIO for the LED will define PICO_DEFAULT_LED_PIN
    // so we can use normal GPIO functionality to turn the led on and off
    gpio_init(PICO_DEFAULT_LED_PIN);
    gpio_set_dir(PICO_DEFAULT_LED_PIN, GPIO_OUT);
    return PICO_OK;
#elif defined(CYW43_WL_GPIO_LED_PIN)
    // For Pico W devices we need to initialise the driver etc
    return cyw43_arch_init();
#endif
}

// Turn the led on or off
static void pico_set_led(bool led_on) {
#if defined(PICO_DEFAULT_LED_PIN)
    // Just set the GPIO on or off
    gpio_put(PICO_DEFAULT_LED_PIN, led_on);
#elif defined(CYW43_WL_GPIO_LED_PIN)
    // Ask the wifi "driver" to set the GPIO on or off
    cyw43_arch_gpio_put(CYW43_WL_GPIO_LED_PIN, led_on);
#endif
}

static void blink_led(int freq, int count)
{
    while (count) {
        pico_set_led(true);
        sleep_ms(freq);
        pico_set_led(false);
        sleep_ms(freq);
        if (count > 0) {
            count--;
        }
    }
}


// this is a dirt-simple 8x8 bitmap font, each bit an on-or-off pixel,
// capital A-Z and nothing else. This is just for debug messages on failure,
// but only eats 208 bytes of memory for the font bitmap!
// The bits are organized so an entire glyph is linear in memory, first row
// then next, each row is one byte worth of bits, then the next glyph
// follows directly after. Also the glyphs are rotated 90 degrees so we can
// blast the directly to the display in landscape mode.
#define debug_fontbits_width 208  // pixel width of whole bitmap.
#define debug_fontbits_height 8
#define debug_fontbits_glyph_w 8 //debug_fontbits_width / 26;
static const uint8_t debug_fontbits[] = {
    0x1F, 0x28, 0x48, 0x88, 0x48, 0x28, 0x1F, 0x0, 0xFF, 0x91, 0x91, 0x91, 
    0x91, 0x52, 0x2C, 0x0, 0x7E, 0x81, 0x81, 0x81, 0x81, 0x81, 0x42, 0x0,
    0xFF, 0x81, 0x81, 0x81, 0x81, 0x42, 0x3C, 0x0, 0xFF, 0x91, 0x91, 0x91,
    0x91, 0x81, 0x81, 0x0, 0xFF, 0x90, 0x90, 0x90, 0x90, 0x80, 0x80, 0x0,
    0x7E, 0x81, 0x81, 0x81, 0x91, 0x91, 0x9E, 0x0, 0xFF, 0x10, 0x10, 0x10,
    0x10, 0x10, 0xFF, 0x0, 0x81, 0x81, 0x81, 0xFF, 0x81, 0x81, 0x81, 0x0,
    0x2, 0x1, 0x81, 0x81, 0xFE, 0x80, 0x80, 0x0, 0xFF, 0x10, 0x28, 0x44,
    0x82, 0x1, 0x0, 0x0, 0xFF, 0x1, 0x1, 0x1, 0x1, 0x1, 0x1, 0x0, 0xFF,
    0x80, 0x60, 0x18, 0x60, 0x80, 0xFF, 0x0, 0xFF, 0x60, 0x30, 0x18, 0xC,
    0x6, 0xFF, 0x0, 0x7E, 0x81, 0x81, 0x81, 0x81, 0x81, 0x7E, 0x0, 0xFF,
    0x88, 0x88, 0x88, 0x88, 0x88, 0x70, 0x0, 0x7E, 0x81, 0x85, 0x83, 0x81,
    0x81, 0x7E, 0x0, 0xFF, 0x88, 0x88, 0x88, 0x88, 0x94, 0xE3, 0x0, 0x66,
    0x91, 0x91, 0x91, 0x91, 0x91, 0x8E, 0x0, 0x80, 0x80, 0x80, 0xFF, 0x80,
    0x80, 0x80, 0x0, 0xFE, 0x1, 0x1, 0x1, 0x1, 0x1, 0xFE, 0x0, 0xC0, 0x30,
    0xE, 0x1, 0xE, 0x30, 0xC0, 0x0, 0xFE, 0x1, 0x2, 0x3C, 0x2, 0x1, 0xFE,
    0x0, 0x83, 0x44, 0x28, 0x10, 0x28, 0x44, 0x83, 0x0, 0x0, 0xC0, 0x30,
    0xF, 0x30, 0xC0, 0x0, 0x0, 0x83, 0x87, 0x8D, 0x99, 0xB1, 0xE1, 0xC1,
    0x0,
};


static uint8_t *init_eink_display_internal(void)
{
    const UWORD Imagesize = ((EPD_3IN7_WIDTH % 4 == 0)? (EPD_3IN7_WIDTH / 4 ): (EPD_3IN7_WIDTH / 4 + 1)) * EPD_3IN7_HEIGHT;
    uint8_t *retval = (uint8_t *) malloc(Imagesize);
    if (!retval) {
        return NULL;
    } else if (DEV_Module_Init() != 0) {
        free(retval);
        return NULL;
    }

    memset(retval, 0xFF, Imagesize);  // make whole framebuffer white.

	EPD_3IN7_4Gray_Init();
    EPD_3IN7_4Gray_Clear();
    sleep_ms(500);

    return retval;
}

static uint8_t *init_eink_display(void)
{
    uint8_t *retval = init_eink_display_internal();
    if (!retval) {
        // if we can't init the display, just blink the LED to report that we're screwed.
        blink_led(250, -1);
    }
    return retval;
}

static void quit_eink_display(void)
{
    sleep_ms(2000); //important, at least 2s
    DEV_Module_Exit();
}

static void draw_debug_glyph(uint8_t *framebuffer, int x, int y, char ch)
{
    if ((ch >= 'a') && (ch <= 'z')) {
        ch += 'A' - 'a';  // we only have capital letters.
    } else if (!((ch >= 'A') && (ch <= 'Z'))) {
        return;   // skip glyphs that aren't in this range, leave a space.
    }

    // since each glyph is exactly 8 pixels wide, with one bit per pixel,
    // it's trivial to find the top level of the glyph in the bits, since
    // each byte is a full row of a glyph. Just jump index*height to the
    // start of the glyph!
    const int glyph_index = (int) (ch - 'A');
    const uint8_t *src = &debug_fontbits[glyph_index * debug_fontbits_height];

    // the display is rotated 90 degrees, but we baked this into the fontbits.
    const int original_rotated_x = EPD_3IN7_WIDTH - y - 1;
    uint8_t *dst = framebuffer + ((original_rotated_x / 4) + (x * (EPD_3IN7_WIDTH / 4)));

    uint8_t byte;

    for (int i = 0; i < 8; i++) {
        const uint8_t byte = *(src++);
        dst[0] = ((byte & (1 << 0)) ? 0x0 : (0x3 << 6)) | ((byte & (1 << 1)) ? 0x0 : (0x3 << 4)) | ((byte & (1 << 2)) ? 0x0 : (0x3 << 2)) | ((byte & (1 << 3)) ? 0x0 : (0x3 << 0));
        dst[1] = ((byte & (1 << 4)) ? 0x0 : (0x3 << 6)) | ((byte & (1 << 5)) ? 0x0 : (0x3 << 4)) | ((byte & (1 << 6)) ? 0x0 : (0x3 << 2)) | ((byte & (1 << 7)) ? 0x0 : (0x3 << 0));
        dst += (EPD_3IN7_WIDTH / 4);
    }
}


static void draw_debug_message(uint8_t *framebuffer, const char *msg)
{
    const UWORD Imagesize = ((EPD_3IN7_WIDTH % 4 == 0)? (EPD_3IN7_WIDTH / 4 ): (EPD_3IN7_WIDTH / 4 + 1)) * EPD_3IN7_HEIGHT;
    memset(framebuffer, 0xFF, Imagesize);  // make whole framebuffer white.

    int msglen = 0;
    while (msg[msglen]) {
        msglen++;
    }

    // display is rotated 90 degress to landscape, so we switch WIDTH and HEIGHT here.
    const int maxmsglen = (EPD_3IN7_HEIGHT / debug_fontbits_glyph_w);
    if (msglen > maxmsglen) {
        msglen = maxmsglen;
    }

    const int y = (EPD_3IN7_WIDTH - debug_fontbits_height) / 2;
    int x = (EPD_3IN7_HEIGHT - (debug_fontbits_glyph_w * msglen)) / 2;

    for (int i = 0; i < msglen; i++) {
        draw_debug_glyph(framebuffer, x, y, msg[i]);
        x += debug_fontbits_glyph_w;
    }

    EPD_3IN7_4Gray_Display(framebuffer);
}

typedef struct http_request
{
    #if USE_TLS
    struct altcp_tls_config *tls_config;
    altcp_allocator_t tls_allocator;
    #endif
    httpc_connection_t settings;
    uint32_t http_response_code;
    httpc_result_t result;
    bool complete;
} http_request;

static err_t http_header(httpc_state_t *connection, void *arg, struct pbuf *hdr, u16_t hdr_len, u32_t content_len)
{
    return ERR_OK;
}

static void http_result(void *arg, httpc_result_t httpc_result, u32_t rx_content_len, u32_t srv_res, err_t err)
{
    http_request *req = (http_request *) arg;
    req->http_response_code = srv_res;
    req->complete = true;
    req->result = httpc_result;
}

static err_t http_recv(void *arg, struct altcp_pcb *conn, struct pbuf *p, err_t err)
{
    return ERR_OK;
}

#if USE_TLS
static struct altcp_pcb *altcp_tls_alloc_sni(void *arg, u8_t ip_type)
{
    http_request *req = (http_request *) arg;
    struct altcp_pcb *pcb = altcp_tls_alloc(req->tls_config, ip_type);
    if (!pcb) {
        return NULL;
    }
    mbedtls_ssl_set_hostname(altcp_tls_context(pcb), HOST);
    return pcb;
}
#endif

int main(void) {
    int exit_code = 1;

    pico_led_init();

    uint8_t *framebuffer = init_eink_display();

    draw_debug_message(framebuffer, "CONNECTING TO WIFI");

    cyw43_arch_enable_sta_mode();

    while (cyw43_arch_wifi_connect_timeout_ms(WIFI_SSID, WIFI_PASSWORD, CYW43_AUTH_WPA2_AES_PSK, 30000)) {
        draw_debug_message(framebuffer, "RETRYING CONNECTION TO WIFI");
        sleep_ms(1000);
    }

    uint16_t iteration = 0;
    while (true) {
        if (iteration >= 10000) {
            iteration = 0;
        }

        draw_debug_message(framebuffer, "CONNECTING TO WEB SERVER");

        static char url[] = URL_REQUEST "?dispw=480&disph=240&zip=" ZIPCODE "&rn=0000";

        char *iterstr = (url + sizeof (url)) - 6;
        unsigned int counter = iteration;
        #define SETNUMCHAR(place) { \
            const unsigned int chunk = counter / place; \
            *(iterstr++) = '0' + chunk; \
            counter -= chunk * place; \
        }
        SETNUMCHAR(1000);
        SETNUMCHAR(100);
        SETNUMCHAR(10);
        SETNUMCHAR(1);
        #undef SETNUMCHAR

        async_context_t *context = cyw43_arch_async_context();
        http_request req;
        memset(&req, '\0', sizeof (req));

        req.settings.result_fn = http_result;

        #if USE_TLS
        req.tls_config = altcp_tls_create_config_client(NULL, 0); // https
        req.tls_allocator.alloc = altcp_tls_alloc_sni;
        req.tls_allocator.arg = &req;
        req.settings.altcp_allocator = &req.tls_allocator;
        #endif

        async_context_acquire_lock_blocking(context);
        const err_t rc = httpc_get_file_dns(HOST, PORT, url, &req.settings, http_recv, &req, NULL);
        async_context_release_lock(context);
        if (rc != ERR_OK) {
            draw_debug_message(framebuffer, "WEB REQUEST FAILED AT STARTUP");
            goto done;
        }

        while (!req.complete) {
            async_context_poll(context);
            async_context_wait_for_work_ms(context, 1000);
        }

        #if USE_TLS
        altcp_tls_free_config(req.tls_config);
        #endif

        draw_debug_message(framebuffer, ((req.result == HTTPC_RESULT_OK) && (req.http_response_code == 200)) ? "WEB REQUEST SUCCESSFUL" : "WEB REQUEST FAILED");

        iteration++;
break;  // !!! FIXME
    }

    exit_code = 0;

done:
    blink_led(100, 10);

    if (framebuffer) {
        EPD_3IN7_Sleep();
        quit_eink_display();
    }

    cyw43_arch_deinit();
    return exit_code;
}

