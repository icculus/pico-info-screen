cmake_minimum_required(VERSION 3.13)
include(pico_sdk_import.cmake)
project(pico-info-screen C CXX ASM)

set(CMAKE_C_STANDARD 11)
set(CMAKE_CXX_STANDARD 17)

pico_sdk_init()

add_executable(pico-info-screen
    pico-info-screen.c
    waveshare-pico-epaper/DEV_Config.c
    waveshare-pico-epaper/EPD_3in7.c
)

target_include_directories(pico-info-screen PRIVATE ${CMAKE_CURRENT_LIST_DIR})

#pico_enable_stdio_usb(pico-info-screen 1)  # enable serial output via USB
#pico_enable_stdio_uart(pico-info-screen 1)  # enable serial output via UART
#pico_add_extra_outputs(pico-info-screen)

if (PICO_CYW43_SUPPORTED)
    target_link_libraries(pico-info-screen
        pico_cyw43_arch_lwip_threadsafe_background
        pico_lwip_http
        pico_lwip_mbedtls
        pico_mbedtls
    )
endif()

target_link_libraries(pico-info-screen pico_stdlib hardware_spi)
