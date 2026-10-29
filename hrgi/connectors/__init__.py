from .base import Connector, ConnectorStatus
from .eonet import EonetConnector
from .gdacs import GdacsConnector
from .gdelt import GdeltConnector
from .usgs import UsgsConnector


def default_connectors() -> list[Connector]:
    return [UsgsConnector(), GdacsConnector(), EonetConnector(), GdeltConnector()]


__all__ = ["Connector", "ConnectorStatus", "default_connectors"]
