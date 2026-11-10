import asyncio
import logging

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("oracle_bridge")

async def forward(src_reader, src_writer, dst_port):
    try:
        dst_reader, dst_writer = await asyncio.open_connection("127.0.0.1", dst_port)
    except Exception as e:
        logger.error(f"Failed to connect to local Oracle tunnel (127.0.0.1:{dst_port}): {e}")
        src_writer.close()
        return

    async def pipe(r, w):
        try:
            while True:
                data = await r.read(8192)
                if not data:
                    break
                w.write(data)
                await w.drain()
        except Exception:
            pass
        finally:
            try:
                w.close()
            except Exception:
                pass

    await asyncio.gather(pipe(src_reader, dst_writer), pipe(dst_reader, src_writer))

async def start_bridge(port):
    server = await asyncio.start_server(
        lambda r, w: forward(r, w, port),
        "172.25.0.1",
        port,
        reuse_address=True
    )
    logger.info(f"Oracle Bridge listening on 172.25.0.1:{port} -> 127.0.0.1:{port}")
    return server

async def main():
    s1 = await start_bridge(1521)
    try:
        s2 = await start_bridge(11521)
        servers = [s1, s2]
    except Exception:
        servers = [s1]
    
    await asyncio.gather(*[s.serve_forever() for s in servers])

if __name__ == "__main__":
    asyncio.run(main())
