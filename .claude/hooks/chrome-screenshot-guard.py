#!/usr/bin/env python3
"""
PreToolUse hook to block PNG screenshots from Chrome DevTools MCP.

Root cause: MCP bug where PNG screenshots are incorrectly declared with
image/jpeg media type, causing API rejection errors that break the chat session.

Solution: Force Claude to use JPEG format for screenshots.
"""
import sys
import json

def main():
    input_data = json.load(sys.stdin)
    tool_name = input_data.get("tool_name", "")
    tool_input = input_data.get("tool_input", {})

    # Block non-JPEG screenshots from Chrome DevTools MCP
    if "take_screenshot" in tool_name:
        screenshot_format = tool_input.get("format", "png")

        if screenshot_format != "jpeg":
            print("BLOCKED: Chrome DevTools screenshot must use JPEG format", file=sys.stderr)
            print("", file=sys.stderr)
            print("MCP bug: PNG screenshots fail with media type mismatch error", file=sys.stderr)
            print("This breaks the chat session and prevents further communication.", file=sys.stderr)
            print("", file=sys.stderr)
            print(f"Current: format='{screenshot_format}'", file=sys.stderr)
            print("Required: format='jpeg'", file=sys.stderr)
            print("", file=sys.stderr)
            print("Solution: Retry the screenshot with format='jpeg' parameter.", file=sys.stderr)
            sys.exit(2)  # Block tool execution

    sys.exit(0)  # Allow tool execution

if __name__ == "__main__":
    main()